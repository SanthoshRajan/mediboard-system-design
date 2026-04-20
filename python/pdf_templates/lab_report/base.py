# /var/www/mediboard/app/PythonScripts/pdf_templates/lab_report/base.py
"""
Lab Report PDF Template.
Generates formatted PDF reports for laboratory test results.
"""
from pdf_generator import PDFBase
import re
import os
import json
import logging
from PIL import Image
from typing import Dict, Any
from datetime import datetime
from config import *
from common import *

logger = logging.getLogger(__name__)

class LabReportBasePDF(PDFBase):
    """PDF template for Lab Reports."""

    def __init__(self, report_data: Dict[str, Any], highlight: bool = False, *args, **kwargs):
        super().__init__(report_data, *args, **kwargs)
        self.facility = report_data['facility']
        self.patient_details = report_data['patient_details']
        self.highlight = highlight  # controls abnormal bolding
        self.logo_path = os.path.dirname(__file__)

        # Define column widths (adjust as needed)
        self.col_widths = {
            's_no': 10,             # Fixed width for S.No. (small numeric values)
            'investigation': 68,    # Wider column for investigation text
            'result': 30,           # Moderate width for result
            'units': 28,            # Moderate width for units
            'ref_range': 40         # Wider column for reference range
        }

    def header(self) -> None:
        header_line_width = 0.2
        line_height = 4
        font_size = 8

        # Add the logo / header-image
        try:
            header_image = self.facility.get('header_image', '')
            group_name = self.facility.get('group_name', '')
            if not header_image:
                raise FileNotFoundError("No header image configured")

            abs_logo_path = f"{STORAGE_BASE}/{group_name}/branding/{header_image}"

            page_width = self.w - 20  # Subtract 10mm margins on each side
            image_width = page_width

            # Calculate the height proportionally (based on image's aspect ratio)
            with Image.open(abs_logo_path) as img:
                aspect_ratio = img.height / img.width
                image_height = image_width * aspect_ratio

            self.image(str(abs_logo_path), x=10, y=4, w=image_width, h=image_height)

        except FileNotFoundError as e:
            logger.error(f"Logo file not found: {abs_logo_path if 'abs_logo_path' in locals() else 'path unknown'}")
            # Set a default height if logo fails
            image_height = 10
            self.set_font('DejaVu', 'B', 14)
            self.cell(0, 10, 'Healthcare Name (Logo Missing)'.encode("utf-8").decode("utf-8"), align='C', ln=True)

        except Exception as e:
            logger.error(f"Failed to load logo: {e}")
            image_height = 10
            self.set_font('DejaVu', 'B', 14)
            self.cell(0, 10, 'Healthcare Name (Logo Error)', align='C', ln=True)

        # Move the cursor below the image
        self.set_y(4 + image_height + 1)  # Add image height and spacing

        # Add a horizontal line below the logo
        self.set_draw_color(128)
        self.set_line_width(header_line_width)

        # Move the cursor further down to avoid overlapping
        self.ln(3)

        # Set font to UTF-8 compatible DejaVuSans
        self.set_font('DejaVu', '', font_size)

        # Patient details in left-right format
        patient_details = [
            ('Patient Name:', self.patient_details.get('patient_name', 'N/A')),
            ('Report ID:', self.patient_details.get('report_id', 'N/A')),
            ('Age/Gender:', f"{self.patient_details.get('patient_age', 'N/A')} / {self.patient_details.get('patient_gender', 'N/A')}"),
            ('Sample Collection Date/Time:', self.patient_details.get('collection_datetime', 'N/A')),
            ('Patient ID:', self.patient_details.get('patient_id', 'N/A')),
            ('Sample Received Date/Time:', self.patient_details.get('received_datetime', 'N/A')),
            ('Referred by:', self.patient_details.get('referred_by', 'N/A')),
            ('Report Date/Time:', self.patient_details.get('report_datetime', 'N/A')),
        ]

        # Render details in two-column layout
        col_width = self.w / 2 - 10  # Half-page width minus margin
        for i in range(0, len(patient_details), 2):
            self.set_x(10)  # Set left margin

            # Left column
            if i < len(patient_details):
                left_label, left_value = patient_details[i]
                self.cell(col_width, line_height, f"{left_label} {left_value}".encode("utf-8").decode("utf-8"), border=0, align='L')

            # Right column
            if i + 1 < len(patient_details):
                right_label, right_value = patient_details[i + 1]
                self.cell(col_width, line_height, f"{right_label} {right_value}".encode("utf-8").decode("utf-8"), border=0, align='L')

            self.ln(5)  # Move to next row

        self.ln(2)

        # Add another horizontal line below patient details
        self.set_draw_color(0)
        self.set_line_width(header_line_width)
        self.line(10, self.get_y(), self.w - 10, self.get_y())

        # Add table header with UTF-8 support
        self.set_font('DejaVu', 'B', 10)  # Bold font for header
        self.cell(self.col_widths['s_no'], 6, 'S.No.'.encode("utf-8").decode("utf-8"), border=0, align='C')
        self.cell(self.col_widths['investigation'], 6, 'Investigation'.encode("utf-8").decode("utf-8"), border=0, align='C')
        self.cell(self.col_widths['result'], 6, 'Result'.encode("utf-8").decode("utf-8"), border=0, align='C')
        self.cell(self.col_widths['units'], 6, 'Units'.encode("utf-8").decode("utf-8"), border=0, align='C')
        self.cell(self.col_widths['ref_range'], 6, 'Ref. Range'.encode("utf-8").decode("utf-8"), border=0, align='C')
        self.ln()  # Move to the next row

        self.line(10, self.get_y(), self.w - 10, self.get_y())
        self.ln(2)

    def add_report_content(self, data: Any) -> None:
        """Generates a table in the PDF while ensuring UTF-8 compatibility."""

        CELL_BORDER = 1 if REPORT_CELL_BORDER_ENABLED else 0

        # If data is a string, parse it into a Python dictionary
        if isinstance(data, str):
            try:
                data = json.loads(data)
            except json.JSONDecodeError as e:
                logger.error(f"❌ Failed to decode JSON: {e}")
                return  # Exit if parsing fails

        column_mapping = {
            'id': 's_no',
            'name': 'investigation',
            'actual_value': 'result',
            'ref_units': 'units',
            'ref_value': 'ref_range',
        }

        row_height = 10

        # Use UTF-8 compatible font
        self.set_font('DejaVu', '', 10)

        # Iterate through Categories
        for category, subcategories in data.items():
            if not isinstance(subcategories, dict):
                logger.error(f"⚠️ Invalid format for category '{category}'")
                continue

            # Add Category Header (UTF-8 encoded)
            self.set_font('DejaVu', 'B', 10)
            self.cell(0, 8, category.encode("utf-8").decode("utf-8"), border=0, ln=True, align='L')

            # Iterate through Subcategories
            for subcategory, tests in subcategories.items():
                if not isinstance(tests, dict):
                    logger.error(f"⚠️ Invalid format for subcategory '{subcategory}'")
                    continue

                # Add Sub-Category Header
                self.set_font('DejaVu', 'B', 10)
                self.cell(0, 6, f"  {subcategory}".encode("utf-8").decode("utf-8"), border=0, ln=True, align='L')
                self.ln(2)

                # Iterate through Test Data
                self.set_font('DejaVu', '', 10)
                for i, test in enumerate(tests.values(), start=1):
                    if not isinstance(test, dict):
                        logger.error(f"⚠️ Invalid test data in subcategory '{subcategory}'")
                        continue
                    test_id = int(test.get('id', 0))
                    # Set ID for Row Number
                    test['id'] = f"{str(i)}."

                    note = (test.get('note', '') or '').strip()

                    abnormal = False
                    if self.highlight and REPORT_ABNORMAL_DETECTION:
                        try:
                            structured_raw = self.report_data.get('ref_value_structured_map', {}).get(test_id)

                            structured = None

                            if structured_raw:
                                structured = (
                                    json.loads(structured_raw)
                                    if isinstance(structured_raw, str)
                                    else structured_raw
                                )

                            # Resolve gender: 'm'/'f' → 'male'/'female'
                            gender_code = self.patient_details.get('patient_gender_raw', '')
                            patient_gender = (
                                'male' if gender_code == 'm'
                                else 'female' if gender_code == 'f'
                                else None
                            )

                            flag = is_abnormal(
                                actual_value=test.get('actual_value', ''),
                                structured=structured,
                                patient_gender=patient_gender,
                                age_dict=self.patient_details.get('patient_age_dict'),
                            )
                            abnormal = flag is True  # None → False (can't determine → don't bold)

                        except Exception as e:
                            logger.warning(f"Abnormal detection failed for test_id={test_id}: {e}")
                            abnormal = False

                    # Process text fields for UTF-8
                    for key, value in test.items():
                        if key == 'note':
                            continue
                        processed_value = process_string(value, key)
                        test[key] = str(processed_value) if isinstance(processed_value, (str, int, float)) else 'N/A'

                    test_lines = {}

                    # Calculate the required row height
                    for db_key, table_header in column_mapping.items():
                        cell_text = test.get(db_key, 'N/A').encode("utf-8").decode("utf-8")
                        cell_text = str(cell_text).replace("\r\n", " ").replace("\n", " ")
                        lines = cell_text.split(';') if ';' in cell_text else [cell_text]

                        cleaned_lines = []
                        for line in lines:
                            # Replace multiple spaces with one
                            line = re.sub(r'\s+', ' ', line)
                            # Final trim
                            line = line.strip()
                            cleaned_lines.append(line)

                        test_lines[table_header] = cleaned_lines

                    # 1) Calculate the maximum number of iterations we'll need
                    max_len = max(len(values) for values in test_lines.values())

                    # 2) Loop from 0 up to max_len - 1
                    for i in range(max_len):
                        row_values = {}

                        # 3) Retrieve the i-th value from each column
                        for key, value_list in test_lines.items():
                            row_values[key] = value_list[i] if i < len(value_list) else ''

                        # Ensure text fits in UTF-8
                        row_values = {k: v.encode("utf-8").decode("utf-8") for k, v in row_values.items()}

                        current_y = self.get_y()
                        if current_y + row_height > self.h - 20:
                            # print(f"{current_y} + {row_height} > {self.h} - {self.b_margin}")
                            self.add_page()

                        if CELL_BORDER:
                            r, g, b = REPORT_BORDER_COLOR
                            self.set_draw_color(r, g, b)
                            self.set_line_width(REPORT_BORDER_WIDTH)
                            if max_len == 1:
                                # Single line row - full border
                                line_border = 'TLR' if note else 1
                            elif i == 0:
                                # First line - top + left + right
                                line_border = 'TLR'
                            elif i == max_len - 1:
                                # Last line - bottom + left + right
                                line_border = 'LR' if note else 'BLR'
                            else:
                                # Middle lines - left + right only
                                line_border = 'LR'
                        else:
                            line_border = 0

                        # Render table row (UTF-8 support)
                        self.set_font('DejaVu', '', 8)
                        self.cell(self.col_widths['s_no'], 6, row_values.get('s_no', ''), border=line_border, align='C')
                        self.cell(self.col_widths['investigation'], 6, row_values.get('investigation', ''), border=line_border, align='L')
                        result_font = 'B' if abnormal else ''
                        self.set_font('DejaVu', result_font, 8)
                        self.cell(self.col_widths['result'], 6, row_values.get('result', ''), border=line_border, align='C')
                        self.set_font('DejaVu', '', 8)
                        self.cell(self.col_widths['units'], 6, row_values.get('units', ''), border=line_border, align='C')
                        self.cell(self.col_widths['ref_range'], 6, row_values.get('ref_range', ''), border=line_border, align='L')
                        self.ln()
                        self.set_font('DejaVu', '', 8)

                    if note:
                        # Skip s_no column, span remaining 4 columns
                        note_width = (
                            self.col_widths['investigation'] +
                            self.col_widths['result'] +
                            self.col_widths['units'] +
                            self.col_widths['ref_range']
                        )

                        self.set_font('DejaVu', 'I', 8)
                        if CELL_BORDER:
                            self.cell(self.col_widths['s_no'], 6, '', border='BLR', align='C')
                            note_border = 1
                        else:
                            self.cell(self.col_widths['s_no'], 6, '', border=0, align='C')
                            note_border = 0

                        self.cell(
                            note_width, 6,
                            f"Note: {note}".encode("utf-8").decode("utf-8"),
                            border=note_border, align='L'
                        )
                        self.ln()

                    self.set_font('DejaVu', '', 8)
                    self.set_draw_color(0, 0, 0)
                    self.set_line_width(0.2)


    def add_last_row(self, report_data: Dict[str, Any]) -> None:
        # def add_last_row(self, technician, officer, qr_data):
        # report_data['technician'], report_data['medical_officer']
        """Adds the last row with technician, officer, and QR code, ensuring UTF-8 support."""

        technician = report_data['technician']
        officer = report_data['medical_officer']

        # Calculate remaining space above footer
        footer_height = 25  # Estimated footer height in mm
        row_height = 40  # Estimated last row height in mm
        available_space = self.h - footer_height - row_height

        # Position the last row above the footer
        if self.get_y() > available_space:
            self.add_page()  # Add a new page if there's not enough space

        # Offset values for vertical positioning
        cell_height = 0
        base_offset = 12
        technician_offset = base_offset + 6
        qr_code_offset = 4  # QR code needs to be moved further down
        base_y = self.h - footer_height - row_height + technician_offset

        # Define margins
        left_margin = 10
        right_margin = 10
        page_width = self.w - (left_margin + right_margin)  # Effective page width after margins

        # Column widths as percentages of the effective page width
        col_widths = {
            'technician': 0.2 * page_width,
            'qr_code': 0.6 * page_width,
            'officer': 0.2 * page_width,
        }

        # Set font to UTF-8 compatible font
        self.set_font('DejaVu', '', 6)

        # Lab Technician Column
        self.set_y(base_y)  # Adjust base Y position for technician
        self.set_x(left_margin)  # Start from the left margin

        # Technician Signature
        tech_signature_path = technician.get('signature_path')
        if tech_signature_path:
            self.image(tech_signature_path, x=self.get_x(), y=self.get_y(), w=30, h=15)
        self.ln(18)  # Move below signature

        # Technician Name (UTF-8 Support)
        self.set_x(left_margin)
        self.cell(col_widths['technician'], cell_height,
                  technician.get('name', 'N/A').encode("utf-8").decode("utf-8"),
                  border=0, align='L')
        self.ln(5)  # Move below name

        # Technician Designation (UTF-8 Support)
        self.set_x(left_margin)
        self.cell(col_widths['technician'], cell_height,
                  technician.get('designation', 'N/A').encode("utf-8").decode("utf-8"),
                  border=0, align='L')

        # QR Code Column
        self.set_y(base_y + qr_code_offset)  # Adjust Y position for QR code
        qr_code_x = left_margin + col_widths['technician'] + (col_widths['qr_code'] - 20) / 2  # Center the QR code
        qr_output_path = STORAGE_BASE / report_data['facility']['group_name'] / 'reports'
        qr_image_path = generate_qr_code(report_data['facility']['id'], report_data['report_id'], 'lab_report', qr_output_path)
        self.image(qr_image_path, x=qr_code_x, y=self.get_y(), w=20, h=20)

        # Medical Officer Column
        self.set_y(base_y)  # Reset Y position for officer (same as technician)
        officer_x = left_margin + col_widths['technician'] + col_widths['qr_code']  # Move to Officer column
        self.set_x(officer_x)

        # Officer Signature
        officer_signature_path = officer.get('signature_path')
        if officer_signature_path:
            self.image(officer_signature_path, x=self.get_x(), y=self.get_y(), w=30, h=15)
        self.ln(18)  # Move below signature

        # Officer Name (UTF-8 Support)
        self.set_x(officer_x)
        self.cell(col_widths['officer'], cell_height,
                  officer.get('name', 'N/A').encode("utf-8").decode("utf-8"),
                  border=0, align='L')
        self.ln(3)  # Move below name

        # Check if doctor_reg_num exists and is a valid non-empty string
        if "doctor_reg_num" in officer and isinstance(officer["doctor_reg_num"], str) and officer["doctor_reg_num"].strip():
            self.set_x(officer_x)
            self.cell(
                col_widths['officer'], cell_height,
                f"Reg. No. : {officer.get('doctor_reg_num', '')}".encode("utf-8").decode("utf-8")
            ,border=0, align='L')
            self.ln(3)  # Move below name
        else:
            self.ln(2)

        # Officer Designation (UTF-8 Support)
        self.set_x(officer_x)
        self.cell(col_widths['officer'], cell_height,
                  officer.get('designation', 'N/A').encode("utf-8").decode("utf-8"),
                  border=0, align='L')

    def footer(self) -> None:
        """Generates the footer section, ensuring UTF-8 compatibility with bold font support."""

        # Position at 25 mm from bottom
        self.set_y(-22)

        line_height = 3
        font_size = 6
        text_color = 50
        line_h_contact = 3

        # Access facility information
        facility_name = self.facility.get('full_name', 'Unknown Name')
        facility_address = self.facility.get('full_address', 'Unknown Address')
        facility_phone_num = self.facility.get('phone_num', ' ')
        facility_map_link = self.facility.get('map_link', ' ')
        facility_email_id = self.facility.get('email_id', ' ')
        facility_website = self.facility.get('website', ' ')

        self.set_font('DejaVu', '', font_size)
        self.set_text_color(text_color)  # Dark gray text

        # Draw a thin line (gray color for opacity effect)
        self.set_draw_color(128)  # Gray color
        self.set_line_width(0.1)
        # self.line(10, self.get_y(), 200, self.get_y()) # Horizontal line - Old Method
        self.line(10, self.get_y(), self.w - 10, self.get_y())  # Horizontal line

        self.ln(1)  # Space between sections

        # Declaration block (Bold + UTF-8)
        self.set_font('DejaVu', 'B', font_size)  # ✅ Use Bold Font
        declaration_text = (
            "This is an Electronically Generated Report. This report is based on the specimen's received. "
            "The report may need to be correlated clinically as laboratory investigations are dependent on multiple variables. "
            "These results should not be reproduced in part."
        ).encode("utf-8").decode("utf-8")

        # Add wrapped text within the specified width
        page_width = self.w  # Total page width
        available_width = page_width - 10  # Subtract 5 mm from each side
        self.multi_cell(available_width, line_height, declaration_text, align='C')

        self.ln(1)  # Space between sections
        # Draw another horizontal line
        self.line(10, self.get_y(), self.w - 10, self.get_y())

        self.ln(1)  # Space between sections

        # Address block
        self.set_font('DejaVu', '', font_size)  # Switch back to regular font
        address_text = facility_address.encode("utf-8").decode("utf-8")

        # Add wrapped text within the specified width
        self.multi_cell(available_width, line_height, address_text, align='C')

        # Positioning for inline content
        start_x = 10  # Left margin
        self.set_x(start_x)

        # Telephone
        telephone_text = f"Tel: {facility_phone_num}".encode("utf-8").decode("utf-8")
        self.cell(65, line_h_contact, telephone_text, align='L', border=0)

        self.set_font('DejaVu', 'U', font_size)  # Underlined font for links

        # Email (clickable link)
        self.set_text_color(0, 102, 204)  # Blue for links
        email_text = f"Email: {facility_email_id}".encode("utf-8").decode("utf-8")
        email_link = f"mailto:{facility_email_id}"
        self.cell(65, line_h_contact, email_text, align='C', border=0, link=email_link)

        # Website (clickable link)
        normalized_url = normalize_website_url(facility_website)
        website_text = f"Web: {normalized_url}".encode("utf-8").decode("utf-8")
        self.cell(0, line_h_contact, website_text, align='R', border=0, link=facility_website)

        # Add a line and spacing for page number and timestamp
        self.ln(4)
        self.line(10, self.get_y(), self.w - 10, self.get_y())

        self.ln(1)
        # Page number and timestamp
        self.set_font('DejaVu', 'I', font_size)  # Italic font for page number
        self.set_text_color(text_color)
        self.cell(0, line_height, f"Page {self.page_no()} of {{nb}}".encode("utf-8").decode("utf-8"), align='L')

        # Generate human-readable timestamp
        timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S").encode("utf-8").decode("utf-8")
        self.cell(0, line_height, timestamp, align='R')
