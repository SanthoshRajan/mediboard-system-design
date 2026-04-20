"""
Default lab report variant.
Thin wrapper over Base.
"""
from .base import LabReportBasePDF

class LabReportDefaultPDF(LabReportBasePDF):
    """Default lab report layout. No overrides - inherits everything from base."""
    pass
