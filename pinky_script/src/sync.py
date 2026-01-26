"""Synchronization logic for ZKTeco to MySQL data transfer."""

import logging
from dataclasses import dataclass
from datetime import datetime
from typing import List

from src.config import Config
from src.database.connection import DatabaseConnection
from src.database.models import DatabaseModels
from src.zkteco.client import ZKTecoClient

logger = logging.getLogger(__name__)


@dataclass
class SyncResult:
    """Result of a device synchronization operation."""

    ip_address: str
    success: bool
    users_synced: int = 0
    attendance_synced: int = 0
    fingerprints_synced: int = 0
    error: str = ""


@dataclass
class SyncSummary:
    """Summary of all synchronization operations."""

    start_time: datetime
    end_time: datetime
    total_devices: int
    successful_devices: int
    failed_devices: int
    total_users: int
    total_attendance: int
    total_fingerprints: int
    results: List[SyncResult]


class Synchronizer:
    """Handles synchronization between ZKTeco devices and MySQL database."""

    def __init__(self, config: Config) -> None:
        """Initialize synchronizer.

        Args:
            config: Application configuration.
        """
        self.config = config
        self.db = DatabaseConnection(config.mysql)
        self.models = DatabaseModels(self.db)

    def sync_device(self, ip_address: str) -> SyncResult:
        """Synchronize data from a single device.

        Args:
            ip_address: Device IP address.

        Returns:
            SyncResult with synchronization details.
        """
        result = SyncResult(ip_address=ip_address, success=False)

        logger.info("Starting sync for device %s", ip_address)

        client = ZKTecoClient(
            ip_address=ip_address,
            port=self.config.zkteco.port,
            timeout=self.config.zkteco.timeout,
            force_udp=self.config.zkteco.force_udp,
            ommit_ping=self.config.zkteco.ommit_ping,
            retry_attempts=self.config.zkteco.retry_attempts,
            retry_delay=self.config.zkteco.retry_delay,
            try_both_protocols=self.config.zkteco.try_both_protocols,
        )

        device_info, users, attendance, fingerprints = client.get_all_data()

        if device_info is None:
            result.error = "Failed to connect to device"
            logger.error("Sync failed for %s: %s", ip_address, result.error)
            return result

        try:
            device_info.last_sync = datetime.now()
            device_id = self.models.upsert_device(device_info)

            if device_id == 0:
                result.error = "Failed to register device in database"
                logger.error("Sync failed for %s: %s", ip_address, result.error)
                return result

            if users:
                self.models.upsert_users(device_id, users)
                result.users_synced = len(users)

            if attendance:
                self.models.upsert_attendance(device_id, attendance)
                result.attendance_synced = len(attendance)

            if fingerprints:
                self.models.upsert_fingerprints(device_id, fingerprints)
                result.fingerprints_synced = len(fingerprints)

            self.models.update_device_last_sync(device_id)

            result.success = True
            logger.info(
                "Sync completed for %s: %d users, %d attendance, %d fingerprints",
                ip_address,
                result.users_synced,
                result.attendance_synced,
                result.fingerprints_synced,
            )

        except Exception as e:
            result.error = str(e)
            logger.error("Sync failed for %s: %s", ip_address, result.error)

        return result

    def sync_all(self) -> SyncSummary:
        """Synchronize data from all configured devices.

        Returns:
            SyncSummary with overall synchronization results.
        """
        start_time = datetime.now()
        results: List[SyncResult] = []

        logger.info(
            "Starting synchronization for %d devices",
            len(self.config.zkteco.devices),
        )

        for ip_address in self.config.zkteco.devices:
            ip_address = ip_address.strip()
            if ip_address:
                result = self.sync_device(ip_address)
                results.append(result)

        end_time = datetime.now()

        summary = SyncSummary(
            start_time=start_time,
            end_time=end_time,
            total_devices=len(results),
            successful_devices=sum(1 for r in results if r.success),
            failed_devices=sum(1 for r in results if not r.success),
            total_users=sum(r.users_synced for r in results),
            total_attendance=sum(r.attendance_synced for r in results),
            total_fingerprints=sum(r.fingerprints_synced for r in results),
            results=results,
        )

        duration = (end_time - start_time).total_seconds()
        logger.info(
            "Synchronization completed in %.2f seconds: %d/%d devices successful",
            duration,
            summary.successful_devices,
            summary.total_devices,
        )

        return summary

    def close(self) -> None:
        """Close database connection."""
        self.db.disconnect()
