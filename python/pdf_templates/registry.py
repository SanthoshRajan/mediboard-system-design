from typing import Type, Dict
from pdf_generator import PDFBase
from pdf_templates.lab_report.default import LabReportDefaultPDF
from pdf_templates.prescription import PrescriptionPDF

REGISTRY: Dict[str, Dict[str, Type[PDFBase]]] = {
    "lab_report": {
        "default": LabReportDefaultPDF
    },
    "prescription": {
        "default": PrescriptionPDF
    }
}

_DEFAULT_VARIANT = "default"


def get_pdf_class(report_type: str, variant: str = _DEFAULT_VARIANT) -> Type[PDFBase]:

    report_registry = REGISTRY.get(report_type)

    if not report_registry:
        raise ValueError(f"Unknown report_type='{report_type}'")

    cls = report_registry.get(variant)

    if cls is None and variant != _DEFAULT_VARIANT:
        cls = report_registry.get(_DEFAULT_VARIANT)

    if cls is None:
        raise ValueError(
            f"No PDF class registered for report_type='{report_type}' "
            f"(tried variant='{variant}' and '{_DEFAULT_VARIANT}')"
        )

    return cls