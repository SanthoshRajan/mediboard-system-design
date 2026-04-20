from pdf_generator import PDFBase

class PrescriptionPDF(PDFBase):
    """PDF template for Prescriptions."""

    def __init__(self, report_data):
        super().__init__(report_data)

    def header(self):
        """Custom header for Prescriptions"""
        self.set_font('DejaVu', 'B', 12)
        self.cell(0, 10, "Medical Prescription", align='C', ln=True)
        self.ln(10)

    def footer(self):
        """Custom footer for Prescriptions"""
        self.set_y(-15)
        self.set_font('DejaVu', 'I', 8)
        self.cell(0, 10, f"Prescription ID: {self.report_data['report_id']} - Page {self.page_no()}", align='C')

    def add_report_content(self):
        """Add prescription-specific content."""
        self.add_page()
        self.set_font('DejaVu', '', 10)
        self.cell(0, 10, f"Doctor: {self.report_data['doctor_name']}", ln=True)
        self.cell(0, 10, f"Prescribed Medicines: {self.report_data['medications']}", ln=True)

    def generate(self, output_path):
        """Generate PDF file."""
        self.add_report_content()
        self.output(output_path)
