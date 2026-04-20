<?php

namespace App\Http\Controllers\v1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Log;
use App\Helpers\FileStorage;
use App\Services\PatientService;

/**
 * PatientController
 *
 * Thin HTTP layer - validates input, delegates all business logic
 * to PatientService, and returns structured JSON responses.
 *
 * Responsibilities:
 *   - Request input extraction and validation
 *   - Delegating to PatientService / FileStorage
 *   - Consistent JSON response formatting
 *
 * Intentionally excluded from this file:
 *   - Business logic (lives in PatientService)
 *   - DB queries (live in PatientService)
 *   - File storage strategy (lives in FileStorage helper)
 */
class PatientController extends ApiController
{
    /**
     * Create or update a patient record.
     *
     * Handles two photo upload modes:
     *   1. Base64 selfie from webcam capture
     *   2. Standard multipart file upload (avatar)
     *
     * On success, returns the full patient profile via PatientService::getPatientProfile()
     * so the frontend can update state without a separate GET request.
     */
    public function addUpdatePatient(Request $request)
    {
        $inputs = $request->only([
            'id', 'salutation', 'name', 'gender', 'dob',
            'occup_cat_id', 'occup_desc', 'email_id',
            'mobile_id', 'alt_mobile_id', 'address',
            'city_id', 'province_id', 'country_id',
            'pincode', 'dr_ref', 'membership',
            'patient_history', 'ref1', 'ref2',
            'facility_id', 'selfie',
        ]);

        // Normalise patient_history: accept both JSON string and array
        if (!empty($inputs['patient_history']) && !is_array($inputs['patient_history'])) {
            $inputs['patient_history'] = json_decode($inputs['patient_history'], true);
        }

        // Resolve photo upload mode before validation so 'file' and 'extension'
        // are available as validator inputs
        [$upload_file, $selfie_data, $uploaded_file, $extension] =
            $this->resolvePhotoUpload($request, $inputs);

        $v = $this->validateRequest($inputs, [
            'id'             => 'nullable|numeric',
            'salutation'     => 'nullable|string',
            'name'           => 'required|string|min:3|regex:/^[a-zA-Z0-9 .\'-]+$/',
            'gender'         => 'required|string|in:m,f,o',
            'dob'            => 'required|date_format:Y-m-d',
            'occup_cat_id'   => 'required',
            'email_id'       => 'nullable|email',
            'mobile_id'      => 'nullable|digits_between:8,15',
            'address'        => 'nullable|string',
            'city_id'        => 'nullable|numeric',
            'province_id'    => 'nullable|numeric',
            'country_id'     => 'required|numeric',
            'patient_history'                        => 'nullable|array',
            'patient_history.medical_history'        => 'nullable|array',
            'patient_history.habit_history'          => 'nullable|array',
            'patient_history.allergy_history'        => 'nullable|array',
            'patient_history.vaccine_history'        => 'nullable|array',
            'ref1'           => 'nullable|string',
            'ref2'           => 'nullable|string',
            'dr_ref'         => 'nullable|string',
            'extension'      => 'nullable|in:jpeg,jpg',
        ]);

        if ($v instanceof JsonResponse) return $v;

        try {
            $patient_id = PatientService::createOrUpdate($inputs);

            if ($upload_file) {
                $this->handlePhotoUpload(
                    $patient_id, $extension, $selfie_data, $uploaded_file
                );
            }
        } catch (\Exception $e) {
            Log::error('Patient save failed', ['error' => $e->getMessage()]);
            return $this->serverError('Failed to save patient record.');
        }

        $profile = PatientService::getPatientProfile([
            'id'          => $patient_id,
            'facility_id' => $inputs['facility_id'],
        ]);

        return $this->success($profile[0]);
    }

    /**
     * Search / retrieve patient profiles.
     *
     * Supports lookup by: id, name, dob, email, mobile, or facility ref number.
     * Returns a list - single-patient detail is just a list of one.
     */
    public function getPatientList(Request $request, $patient_id = null)
    {
        $inputs = $request->all();

        if (!empty($patient_id)) {
            $inputs['id'] = $patient_id;
        }

        $v = $this->validateRequest($inputs, [
            'id'        => 'nullable|numeric',
            'name'      => 'nullable|string|min:3',
            'dob'       => 'nullable|date_format:Y-m-d',
            'email_id'  => 'nullable|email',
            'mobile_id' => 'nullable|digits_between:8,15',
            'ref_id'    => 'nullable|string|min:5',
        ]);

        if ($v instanceof JsonResponse) return $v;

        $output = PatientService::getPatientProfile($inputs);

        return $this->success($output);
    }

    /**
     * Full 360° patient view - complete clinical history across all visits.
     * Delegated entirely to PatientService.
     */
    public function getPatient360($id)
    {
        return PatientService::getPatient360View($id);
    }

    /**
     * 180° patient view - profile + today's appointment + active consultation.
     *
     * Design note: a single optimised query joins patients, appointments,
     * and consultations for the current session date, avoiding N+1 calls
     * at the point of patient check-in (high-frequency path).
     *
     * The result is split into three named keys (profile / appointment /
     * consultation) so the frontend can render each panel independently.
     */
    public function getPatient180(Request $request)
    {
        $inputs = $request->all();

        $v = $this->validateRequest($inputs, [
            'patient_id'     => 'required|numeric',
            'appointment_id' => 'nullable|numeric',
        ]);

        if ($v instanceof JsonResponse) return $v;

        if (empty($inputs['session_date'])) {
            $inputs['session_date'] = Carbon::now()->startOfDay()->toDateString();
        }

        $output = PatientService::getPatient180View($inputs);

        return $this->success($output);
    }

    /**
     * Soft-delete a patient record (sets is_active = 0).
     * Hard deletes are not permitted - all patient data is retained for audit.
     */
    public function markInactive(Request $request)
    {
        $inputs = $request->all();

        $v = $this->validateRequest($inputs, [
            'id'          => 'required|numeric',
            'facility_id' => 'required|numeric',
        ]);

        if ($v instanceof JsonResponse) return $v;

        $result = PatientService::deactivatePatient($inputs['id']);

        return $this->updated(['id' => $inputs['id']]);
    }

    // ──────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Determine upload mode and extract file metadata.
     *
     * Returns: [bool $upload_file, ?string $selfie_b64, ?UploadedFile $file, ?string $extension]
     */
    private function resolvePhotoUpload(Request $request, array &$inputs): array
    {
        if (!empty($inputs['selfie'])) {
            $replace    = substr($inputs['selfie'], 0, strpos($inputs['selfie'], ',') + 1);
            $selfie_b64 = str_replace(' ', '+', str_replace($replace, '', $inputs['selfie']));
            $extension  = explode('/', explode(':', substr($inputs['selfie'], 0, strpos($inputs['selfie'], ';')))[1])[1];
            return [true, $selfie_b64, null, $extension];
        }

        if ($request->hasFile('avatar')) {
            $file                   = $request->file('avatar');
            $extension              = strtolower($file->getClientOriginalExtension());
            $inputs['file']         = $file;
            $inputs['extension']    = $extension;
            return [true, null, $file, $extension];
        }

        return [false, null, null, null];
    }

    /**
     * Store patient photo via FileStorage helper.
     * Keeps upload strategy decoupled from this controller.
     */
    private function handlePhotoUpload(
        int $patient_id,
        string $extension,
        ?string $selfie_b64,
        $uploaded_file
    ): void {
        $file_name = "p-{$patient_id}.{$extension}";

        if (!empty($selfie_b64)) {
            $decoded = base64_decode($selfie_b64, true);
            if ($decoded === false) {
                throw new \Exception('Invalid base64 image data');
            }
            FileStorage::uploadAvatarRaw($file_name, $decoded);
        } else {
            FileStorage::uploadAvatar($uploaded_file, $file_name);
        }

        PatientService::updatePhoto($patient_id, $file_name);
    }

}
