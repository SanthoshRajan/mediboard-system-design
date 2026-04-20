from fpdf import FPDF
from common import *

class PDFBase(FPDF):
    """Base class for PDF reports with common methods."""
    def __init__(self, report_data):
        super().__init__()
        self.report_data = report_data
        self.facility = report_data.get("facility", {})

    def draw_page_border(self):
        """Draw a rectangular border around each page."""
        if not REPORT_BORDER_ENABLED:
            return

        r, g, b = REPORT_BORDER_COLOR
        self.set_draw_color(r, g, b)
        self.set_line_width(REPORT_BORDER_WIDTH)

        m = REPORT_BORDER_MARGIN
        self.rect(
            m,
            m,
            self.w - (2 * m),
            self.h - (2 * m),
        )

        # Reset to defaults
        self.set_draw_color(0, 0, 0)
        self.set_line_width(0.2)

    def header(self):
        """Common header implementation"""
        self.set_font('DejaVu', 'B', 12)
        self.cell(0, 10, "Medical Report", align='C', ln=True)
        # self.cell(0, 10, self.facility.get("full_name", "Medical Report"), align='C', ln=True)
        self.ln(10)

    def footer(self):
        """Common footer implementation"""
        self.set_y(-15)
        self.set_font('DejaVu', 'I', 8)
        self.cell(0, 10, f"Page {self.page_no()} / {{nb}}", align='C')
