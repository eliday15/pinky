"""Configuration module for Pinky Clock.

Loads configuration from environment variables using python-dotenv.
"""

import os
from dataclasses import dataclass, field
from typing import List

from dotenv import load_dotenv

load_dotenv()


@dataclass
class MySQLConfig:
    """MySQL database configuration."""

    host: str = field(default_factory=lambda: os.getenv("MYSQL_HOST", "localhost"))
    port: int = field(default_factory=lambda: int(os.getenv("MYSQL_PORT", "3306")))
    user: str = field(default_factory=lambda: os.getenv("MYSQL_USER", "root"))
    password: str = field(default_factory=lambda: os.getenv("MYSQL_PASSWORD", ""))
    database: str = field(
        default_factory=lambda: os.getenv("MYSQL_DATABASE", "pinky_clock")
    )


@dataclass
class ZKTecoConfig:
    """ZKTeco devices configuration."""

    devices: List[str] = field(
        default_factory=lambda: os.getenv(
            "ZKTECO_DEVICES", "192.168.1.11,192.168.1.12,192.168.1.13,192.168.1.14"
        ).split(",")
    )
    port: int = field(default_factory=lambda: int(os.getenv("ZKTECO_PORT", "4370")))
    timeout: int = field(
        default_factory=lambda: int(os.getenv("ZKTECO_TIMEOUT", "15"))
    )
    force_udp: bool = field(
        default_factory=lambda: os.getenv("ZKTECO_FORCE_UDP", "false").lower() == "true"
    )
    ommit_ping: bool = field(
        default_factory=lambda: os.getenv("ZKTECO_OMMIT_PING", "true").lower() == "true"
    )
    retry_attempts: int = field(
        default_factory=lambda: int(os.getenv("ZKTECO_RETRY_ATTEMPTS", "3"))
    )
    retry_delay: float = field(
        default_factory=lambda: float(os.getenv("ZKTECO_RETRY_DELAY", "1.0"))
    )
    try_both_protocols: bool = field(
        default_factory=lambda: os.getenv("ZKTECO_TRY_BOTH_PROTOCOLS", "true").lower()
        == "true"
    )


@dataclass
class Config:
    """Main application configuration."""

    mysql: MySQLConfig = field(default_factory=MySQLConfig)
    zkteco: ZKTecoConfig = field(default_factory=ZKTecoConfig)


def get_config() -> Config:
    """Get the application configuration.

    Returns:
        Config object with all settings loaded from environment.
    """
    return Config()
