"""
Main script for generating PDF reports.
Handles command-line arguments, data fetching, and PDF generation orchestration.
"""
import os
import sys
import logging
import argparse
from pathlib import Path
from typing import Dict, Any
from dotenv import load_dotenv
from redis.exceptions import RedisError

from common import *
from config import *
from fetch_report import *

from tenant import TenantContext
from pdf_templates.registry import get_pdf_class

# Multi-tenant architecture:
# - Facility resolved via Redis
# - DB dynamically selected via TenantContext

setup_logging()
logger = logging.getLogger(__name__)

# ============================================================================
# ARGUMENT PARSING
# ============================================================================

def parse_arguments() -> argparse.Namespace:
    """Parses command-line arguments and ensures required values are provided."""
    parser = argparse.ArgumentParser(
        description="Generate PDF reports.",
        usage="python generate_report.py <facility_id> <report_type> <report_id> [--highlight]",
    )

    parser.add_argument("facility_id", type=int, help="Facility ID (integer)")
    parser.add_argument(
        "report_type",
        choices=["lab_report", "prescription", "invoice", "clinical_notes"],
        help="Type of report"
    )
    parser.add_argument("report_id", type=int, help="Report ID (integer)")
    parser.add_argument(
        "--highlight",
        action="store_true",
        default=False,
        help="Bold abnormal values in the report"
    )

    args = parser.parse_args()

    # Validate IDs are positive - parser.error() automatically exits
    if args.facility_id <= 0 or args.report_id <= 0:
        parser.error("Facility ID and Report ID must be positive integers")

    return args


# ============================================================================
# PDF GENERATION
# ============================================================================

def generate_pdf(
    report_data: Dict[str, Any],
    report_type: str,
    ctx: TenantContext,
    output_path: Path,
    highlight: bool = False,
) -> str:
    """
    Instantiate the correct PDF class (via registry), render, and save.

    The template variant is sourced from facility["template_variant"] in Redis.
    Falls back to "default" if not set or not registered.
    """

    group_name = report_data['facility']['group_name'].upper()

    # Format patient and report IDs
    report_data['patient_details']['report_id'] = f"{report_data['facility']['prefix']}-{report_data['patient_details']['report_id']}"
    report_data['patient_details']['patient_id'] = f"{group_name}-{report_data['patient_details']['patient_id']}"

    fonts, use_fallback = get_font_paths()

    variant = report_data['facility'].get("template_variant") or "default"

    logger.info(f"Using template: report_type={report_type}, variant={variant}")

    # Select the correct PDF template dynamically
    try:
        PDFClass = get_pdf_class(report_type, variant)
    except ValueError as e:
        logger.error(f"Template resolution failed: {e}")
        raise

    pdf = PDFClass(report_data, highlight=highlight)

    # Register fonts
    if use_fallback:
        pdf.set_font('Arial', '', 10)
    else:
        pdf.add_font('DejaVu', '', str(fonts['regular']), uni=True)
        pdf.add_font('DejaVu', 'B', str(fonts['bold']), uni=True)
        pdf.add_font('DejaVu', 'I', str(fonts['italic']), uni=True)
        pdf.set_font('DejaVu', '', 10)

    # Configure PDF layout
    pdf.set_margins(left=10, top=5, right=10)
    pdf.alias_nb_pages()
    pdf.set_auto_page_break(auto=True, margin=15)
    pdf.add_page()

    pdf.add_report_content(report_data['report_data'])

    # Add Report Footer
    pdf.cell(0, 10, '-' * 70 + ' End of Report ' + '-' * 70, ln=True, align='C') # TODO: move to template base class

    # Add Last Row with Signatures and QR Code
    pdf.add_last_row(report_data)

    # Ensure output directory exists
    output_dir = Path(output_path)
    output_dir.mkdir(parents=True, exist_ok=True)

    # Define output file path
    suffix = "_h" if highlight else ""
    output_file_path = output_dir / f"{report_data['patient_details']['report_id']}{suffix}.pdf"

    # Save PDF with error handling
    try:
        pdf.output(str(output_file_path))
        logger.info(f"PDF generated: {output_file_path.name}")
        return str(output_file_path)
    except Exception as e:
        logger.error(f"Failed to generate PDF. Error: {e}")
        raise RuntimeError(f"PDF generation failed: {e}") from e


# ============================================================================
# ENTRY POINT
# ============================================================================

if __name__ == "__main__":
    exit_code = 0

    try:
        args = parse_arguments()

        logger.info(
            "Processing report: facility_id=%s, report_type=%s, report_id=%s, highlight=%s",
            args.facility_id,
            args.report_type,
            args.report_id,
            args.highlight,
        )

        # 1. Facility config from Redis
        try:
            facility = fetch_facility_data(args.facility_id)

            if not facility:
                logger.error(f"Facility {args.facility_id} not found in Redis")
                sys.exit(1)

        except RedisError as e:
            logger.error(f"Redis connection failed: {e}")
            sys.exit(3)

        # 2. Build TenantContext - single point of DB name construction
        try:
            ctx = TenantContext.from_facility(facility)
        except ValueError as e:
            logger.error(f"Invalid facility data: {e}")
            sys.exit(1)

        # 3. Fetch report data
        try:
            if args.report_type == "lab_report":
                report_data = lab_data(
                    ctx=ctx,
                    report_id=args.report_id
                )
            elif args.report_type == "prescription":
                report_data = prescription_data()  # future
            else:
                raise ValueError(f"Unsupported report_type={args.report_type}")

            if not report_data:
                logger.error(
                    f"No report found for facility_id={args.facility_id}, "
                    f"report_id={args.report_id}"
                )
                sys.exit(1)

        except NotImplementedError as e:
            logger.error(f"Report type not yet implemented: {e}")
            sys.exit(2)
        except Exception as e:
            logger.error(f"Failed to fetch report data: {e}")
            sys.exit(2)

        report_data['facility'] = facility

        # 4. Generate PDF
        try:
            pdf_output_path = STORAGE_BASE / ctx.group_name / "reports"
            pdf_path = generate_pdf(
                report_data=report_data,
                report_type=args.report_type,
                ctx=ctx,
                output_path=pdf_output_path,
                highlight=args.highlight,
            )
            print(pdf_path)  # Output path for Laravel to capture
        except (ValueError, RuntimeError) as e:
            logger.error(f"PDF generation failed: {e}")
            sys.exit(2)

    except ValueError as e:
        logger.error(f"Validation error: {e}")
        exit_code = 2

    except RedisError as e:
        logger.error(f"Redis error: {e}")
        exit_code = 3

    except KeyboardInterrupt:
        logger.info("Process interrupted by user")
        exit_code = 130

    except Exception as e:
        logger.error(f"Unexpected error: {e}", exc_info=True)
        exit_code = 1

    sys.exit(exit_code)
