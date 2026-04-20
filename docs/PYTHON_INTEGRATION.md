# Python Integration

## Overview

PDF generation is handled by a Python service for flexibility and performance.

## Invocation

Laravel executes:

```bash
python generate_report.py <facility_id> <report_type> <report_id>
```

## Input

* facility_id
* report_type (lab_report, prescription)
* report_id

## Output

* Absolute path to generated PDF (stdout)

## Why Python?

* Better PDF libraries (FPDF, PIL)
* Easier layout control
* Isolation from PHP memory limits

## Error Handling

* Exit codes used for failure detection
* Logs captured in Laravel
