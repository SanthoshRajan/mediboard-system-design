# Laravel ↔ Python Integration

## Overview
Mediboard uses Laravel for API orchestration and Python for PDF generation. Integration is done via CLI using exec().

## Execution Flow
1. Client calls API
2. Laravel validates input
3. Laravel runs Python script via exec()
4. Python fetches DB data
5. Python generates PDF
6. File saved to /storage/{tenant}/reports/
7. Python returns file path via stdout
8. Laravel responds to client

## Input
generate_report.py {facility_id} {report_type} {report_id}

## Output
Success: prints file path  
Failure: exit code indicates issue

## Exit Codes
0 = Success  
1 = General error  
2 = Validation/PDF error  
3 = Redis error

## Security
- Always run as www-data
- Avoid root execution
- Controlled arguments prevent injection

## Performance
- exec() is blocking
- Suitable for moderate load
- Can be migrated to queue later

## Future Improvements
- Queue-based execution
- Python microservice
- Async processing
