"""
Configuration module for PDF report generation.
Loads environment variables and sets up paths and connections.
"""
import os
import re
import sys
import logging
import redis
from pathlib import Path
from typing import Optional, Dict, Any
from dotenv import load_dotenv

# Configure logger
logger = logging.getLogger(__name__)

# Configure logging with UTF-8 encoding for stdout
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(filename)s:%(lineno)d - %(funcName)s() - %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout)
    ]
)


# ============================================================================
# PATH CONFIGURATION
# ============================================================================

def get_base_directory() -> Path:
    """
    Determine the base directory by traversing up from current file.
    More robust than regex matching.
    """
    current_path = Path(__file__).resolve()

    # Traverse up to find the base directory (should contain .env)
    for parent in current_path.parents:
        env_file = parent / '.env'
        if env_file.exists():
            logger.info(f"Base directory found: {parent}")
            return parent

    # Fallback: assume structure is /path/to/project/app/PythonScripts/
    # Go up 2 levels
    base_dir = current_path.parent.parent
    logger.warning(f"Could not find .env file, using fallback path: {base_dir}")
    return base_dir

# Get base directory
try:
    base_dir = get_base_directory()
except Exception as e:
    logger.error(f"Failed to determine base directory: {e}")
    raise RuntimeError(f"Base directory could not be determined: {e}")

# Define all paths
env_path = base_dir / '.env'
STORAGE_BASE = Path("/storage")

# Paths
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
FONT_DIR = os.path.join(BASE_DIR, "fonts")
LOGO_DIR = os.path.join(BASE_DIR, "assets/logos")
OUTPUT_DIR = os.path.join(BASE_DIR, "output")

# Verify critical paths exist
for path_name, path_obj in [
    ('STORAGE_BASE', STORAGE_BASE),
]:
    if not path_obj.exists():
        logger.warning(f"{path_name} does not exist: {path_obj}")


# ============================================================================
# ENVIRONMENT VARIABLES
# ============================================================================

# Load environment variables
if not env_path.exists():
    logger.warning(f".env file not found at {env_path}")

load_dotenv(env_path)

# Application settings
APP_URL = os.getenv('APP_URL', '')

if not APP_URL:
    logger.warning("APP_URL is not set in environment")


def str_to_bool(value: Any) -> bool:
    """Convert string to boolean value."""
    if isinstance(value, bool):
        return value
    return str(value).lower() in ('true', '1', 'yes', 'on')

# ============================================================================
# REDIS CONFIGURATION
# ============================================================================

# Redis settings
REDIS_PREFIX = os.getenv('REDIS_PREFIX', '')
REDIS_HOST = os.getenv('REDIS_HOST', '127.0.0.1')
REDIS_PORT = int(os.getenv('REDIS_PORT', '6379'))
REDIS_PASSWORD = os.getenv('REDIS_PASSWORD', None)

# Normalize password (handle 'null', 'None', etc.)
if REDIS_PASSWORD in ['null', 'None', 'none', '', None]:
    REDIS_PASSWORD = None

# Connection pool for better performance
_redis_pool: Optional[redis.ConnectionPool] = None
_redis_client: Optional[redis.Redis] = None


def get_redis_pool() -> redis.ConnectionPool:
    """Get or create Redis connection pool."""
    global _redis_pool

    if _redis_pool is None:
        _redis_pool = redis.ConnectionPool(
            host=REDIS_HOST,
            port=REDIS_PORT,
            password=REDIS_PASSWORD,
            decode_responses=True,
            max_connections=10,
            socket_connect_timeout=5,
            socket_keepalive=True,
            health_check_interval=30
        )
        logger.info(f"Redis connection pool created for {REDIS_HOST}:{REDIS_PORT}")

    return _redis_pool


def get_redis_client() -> redis.Redis:
    """
    Get or create Redis client with connection pooling.
    Tests connection on first use.
    """
    global _redis_client

    if _redis_client is None:
        pool = get_redis_pool()
        _redis_client = redis.Redis(connection_pool=pool)

        # Test connection
        try:
            _redis_client.ping()
            logger.info("Redis connection established successfully")
        except redis.RedisError as e:
            logger.error(f"Failed to connect to Redis: {e}")
            raise ConnectionError(
                f"Failed to connect to Redis at {REDIS_HOST}:{REDIS_PORT}. Error: {e}"
            )

    return _redis_client


def test_redis_connection() -> bool:
    """Test Redis connection and return True if successful."""
    try:
        client = get_redis_client()
        client.ping()
        return True
    except Exception as e:
        logger.error(f"Redis connection test failed: {e}")
        return False


# ============================================================================
# DATABASE CONFIGURATION
# ============================================================================

def get_env_var(var_name, default=None, required=False, errors=None):
    """Fetch environment variable and track missing required ones."""
    value = os.getenv(var_name, default)
    if required and value is None:
        if errors is not None:
            errors.append(var_name)
        return None
    return value

missing_vars = []

DB_CONFIG = {
    'host': get_env_var('DB_HOST', required=True, errors=missing_vars),
    'port': get_env_var('DB_PORT', '3306'),
    'user': get_env_var('DB_USERNAME', required=True, errors=missing_vars),
    'password': get_env_var('DB_PASSWORD', required=True, errors=missing_vars),
    'database': get_env_var('DB_DATABASE', required=True, errors=missing_vars),
}

if missing_vars:
    raise ValueError(f"Missing required environment variables: {', '.join(missing_vars)}")


# ============================================================================
# FEATURE FLAGS
# ============================================================================

REPORT_QR_CODE = str_to_bool(os.getenv('REPORT_QR_CODE', 'false'))
REPORT_ABNORMAL_DETECTION = str_to_bool(os.getenv('REPORT_ABNORMAL_DETECTION', 'false'))

# ============================================================================
# BORDER CONFIGURATION
# ============================================================================

REPORT_BORDER_ENABLED = str_to_bool(os.getenv('REPORT_BORDER', 'false'))
REPORT_CELL_BORDER_ENABLED = str_to_bool(os.getenv('REPORT_CELL_BORDER', 'false'))
REPORT_BORDER_COLOR   = (0, 0, 0)   # RGB black - change to e.g. (200, 200, 200) for grey
REPORT_BORDER_WIDTH   = 0.1         # mm line thickness
REPORT_BORDER_MARGIN  = 5           # mm gap from page edge

# ============================================================================
# EXPORTS
# ============================================================================

# Export only necessary variables
__all__ = [
    # Paths
    'base_dir',
    'BASE_DIR',
    'env_path',
    'STORAGE_BASE',
    'FONT_DIR',
    'LOGO_DIR',
    'OUTPUT_DIR',

    # Database
    'DB_CONFIG',

    # Redis
    'get_redis_client',
    'get_redis_pool',
    'test_redis_connection',
    'REDIS_PREFIX',
    'REDIS_HOST',
    'REDIS_PORT',

    # Application
    'APP_URL',
    'REPORT_QR_CODE',
    'REPORT_ABNORMAL_DETECTION',
    'REPORT_BORDER_ENABLED',
    'REPORT_CELL_BORDER_ENABLED',
    'REPORT_BORDER_COLOR',
    'REPORT_BORDER_WIDTH',
    'REPORT_BORDER_MARGIN',
]