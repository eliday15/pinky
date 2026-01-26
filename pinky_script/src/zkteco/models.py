"""Data models for ZKTeco device data."""

from dataclasses import dataclass
from datetime import datetime
from typing import Optional


@dataclass
class DeviceInfo:
    """Information about a ZKTeco device."""

    ip_address: str
    name: Optional[str] = None
    serial_number: Optional[str] = None
    firmware_version: Optional[str] = None
    last_sync: Optional[datetime] = None


@dataclass
class User:
    """User registered on a ZKTeco device."""

    user_id: int
    name: str
    privilege: int = 0
    password: str = ""
    group_id: int = 0
    card: str = ""


@dataclass
class AttendanceRecord:
    """Attendance record from a ZKTeco device."""

    user_id: int
    timestamp: datetime
    status: int = 0
    punch: int = 0
    uid: Optional[str] = None


@dataclass
class Fingerprint:
    """Fingerprint template from a ZKTeco device."""

    user_id: int
    finger_id: int
    template: bytes
