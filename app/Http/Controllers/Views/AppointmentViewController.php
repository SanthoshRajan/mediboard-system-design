<?php

namespace App\Http\Controllers\Views;

use Illuminate\View\View;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AppointmentViewController extends Controller
{
    public function listPage(): View
    {
        return view('appointment-list', [
            'title'        => 'Appointment - View | Search',
            'pageTitle'    => 'Appointment',
            'pageSubTitle' => 'List',
        ]);
    }

    public function addPage(): View
    {
        return view('appointment-add', [
            'title'        => 'Appointment - View | Add',
            'pageTitle'    => 'Appointment',
            'pageSubTitle' => 'Add / Delete',
        ]);
    }
}
