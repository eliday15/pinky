"""ZKTeco device client using pyzk library.

Connects to ZKTeco biometric devices to extract attendance records,
users, and device information. Includes retry logic and protocol
fallback to handle unreliable UDP connections.
"""

import logging
import time
from typing import List, Optional, Tuple

from zk import ZK
from zk.exception import ZKErrorResponse, ZKNetworkError

from src.zkteco.models import AttendanceRecord, DeviceInfo, Fingerprint, User

logger = logging.getLogger(__name__)

# Minimum percentage of expected records to consider a fetch successful
MIN_FETCH_RATIO = 0.95


class ZKTecoClient:
    """Client for communicating with ZKTeco devices."""

    def __init__(
        self,
        ip_address: str,
        port: int = 4370,
        timeout: int = 15,
        password: int = 0,
        force_udp: bool = False,
        ommit_ping: bool = True,
        retry_attempts: int = 3,
        retry_delay: float = 1.0,
        try_both_protocols: bool = True,
    ) -> None:
        """Initialize ZKTeco client.

        Args:
            ip_address: Device IP address.
            port: Device port (default 4370).
            timeout: Connection timeout in seconds.
            password: Device password (default 0).
            force_udp: Force UDP protocol instead of TCP (default False).
            ommit_ping: Skip ping check before connecting (default True).
            retry_attempts: Number of retry attempts per protocol (default 3).
            retry_delay: Delay in seconds between retries (default 1.0).
            try_both_protocols: Try both TCP and UDP if one fails (default True).
        """
        self.ip_address = ip_address
        self.port = port
        self.timeout = timeout
        self.password = password
        self.force_udp = force_udp
        self.ommit_ping = ommit_ping
        self.retry_attempts = retry_attempts
        self.retry_delay = retry_delay
        self.try_both_protocols = try_both_protocols
        self._zk: Optional[ZK] = None
        self._conn = None
        self._connected_protocol: Optional[str] = None

    def _try_connect(self, force_udp: bool) -> bool:
        """Attempt to connect using a specific protocol.

        Args:
            force_udp: If True, use UDP; if False, try TCP first.

        Returns:
            True if connection successful, False otherwise.
        """
        protocol = "UDP" if force_udp else "TCP"
        try:
            self._zk = ZK(
                self.ip_address,
                port=self.port,
                timeout=self.timeout,
                password=self.password,
                force_udp=force_udp,
                ommit_ping=self.ommit_ping,
            )
            self._conn = self._zk.connect()
            self._connected_protocol = protocol
            logger.info(
                "Connected to ZKTeco device at %s:%d using %s",
                self.ip_address,
                self.port,
                protocol,
            )
            return True
        except (ZKNetworkError, ZKErrorResponse) as e:
            logger.debug(
                "Failed to connect to %s:%d using %s - %s",
                self.ip_address,
                self.port,
                protocol,
                e,
            )
            self._cleanup_connection()
            return False
        except Exception as e:
            logger.debug(
                "Unexpected error connecting to %s:%d using %s - %s",
                self.ip_address,
                self.port,
                protocol,
                e,
            )
            self._cleanup_connection()
            return False

    def _cleanup_connection(self) -> None:
        """Clean up connection resources."""
        if self._conn:
            try:
                self._conn.disconnect()
            except Exception:
                pass
        self._conn = None
        self._zk = None
        self._connected_protocol = None

    def connect(self) -> bool:
        """Connect to the ZKTeco device with retry and protocol fallback.

        Tries to connect using the configured protocol. If try_both_protocols
        is enabled and the first protocol fails after all retries, it will
        try the alternate protocol.

        Returns:
            True if connection successful, False otherwise.
        """
        # Determine protocol order based on force_udp setting
        if self.force_udp:
            protocols = [True, False]  # Try UDP first, then TCP
        else:
            protocols = [False, True]  # Try TCP first, then UDP

        if not self.try_both_protocols:
            protocols = [protocols[0]]  # Only try the preferred protocol

        for force_udp in protocols:
            protocol = "UDP" if force_udp else "TCP"

            for attempt in range(1, self.retry_attempts + 1):
                logger.debug(
                    "Connection attempt %d/%d to %s using %s",
                    attempt,
                    self.retry_attempts,
                    self.ip_address,
                    protocol,
                )

                if self._try_connect(force_udp):
                    return True

                if attempt < self.retry_attempts:
                    time.sleep(self.retry_delay)

            logger.warning(
                "All %d attempts failed for %s using %s",
                self.retry_attempts,
                self.ip_address,
                protocol,
            )

        logger.error(
            "Failed to connect to ZKTeco device at %s:%d after trying all protocols",
            self.ip_address,
            self.port,
        )
        return False

    def disconnect(self) -> None:
        """Disconnect from the ZKTeco device."""
        if self._conn:
            try:
                self._conn.disconnect()
                logger.info("Disconnected from ZKTeco device at %s", self.ip_address)
            except Exception as e:
                logger.warning("Error disconnecting from %s: %s", self.ip_address, e)
            finally:
                self._conn = None
                self._zk = None

    def disable_device(self) -> bool:
        """Disable the device to prevent interference during data extraction.

        Returns:
            True if successful.
        """
        if not self._conn:
            return False
        try:
            self._conn.disable_device()
            logger.debug("Device %s disabled", self.ip_address)
            return True
        except Exception as e:
            logger.warning("Failed to disable device %s: %s", self.ip_address, e)
            return False

    def enable_device(self) -> bool:
        """Re-enable the device after data extraction.

        Returns:
            True if successful.
        """
        if not self._conn:
            return False
        try:
            self._conn.enable_device()
            logger.debug("Device %s enabled", self.ip_address)
            return True
        except Exception as e:
            logger.warning("Failed to enable device %s: %s", self.ip_address, e)
            return False

    def get_device_info(self) -> Optional[DeviceInfo]:
        """Get device information.

        Returns:
            DeviceInfo object or None if failed.
        """
        if not self._conn:
            return None

        try:
            serial = self._conn.get_serialnumber()
            firmware = self._conn.get_firmware_version()
            device_name = self._conn.get_device_name()

            return DeviceInfo(
                ip_address=self.ip_address,
                name=device_name,
                serial_number=serial,
                firmware_version=firmware,
            )
        except Exception as e:
            logger.error("Failed to get device info from %s: %s", self.ip_address, e)
            return DeviceInfo(ip_address=self.ip_address)

    def get_users(self) -> List[User]:
        """Get all users registered on the device.

        Returns:
            List of User objects.
        """
        if not self._conn:
            return []

        try:
            raw_users = self._conn.get_users()
            users = []

            for u in raw_users:
                # Handle group_id which might be empty string
                group_id = 0
                if hasattr(u, "group_id") and u.group_id:
                    try:
                        group_id = int(u.group_id)
                    except (ValueError, TypeError):
                        group_id = 0

                # pyzk returns user_id as string; convert to int
                try:
                    raw_user_id = u.user_id if hasattr(u, "user_id") else str(u.uid)
                    parsed_user_id = int(raw_user_id)
                except (ValueError, TypeError):
                    parsed_user_id = int(u.uid)

                users.append(
                    User(
                        user_id=parsed_user_id,
                        name=u.name or "",
                        privilege=u.privilege if hasattr(u, "privilege") else 0,
                        password=u.password or "",
                        group_id=group_id,
                        card=str(u.card) if hasattr(u, "card") and u.card else "",
                    )
                )

            logger.info("Retrieved %d users from %s", len(users), self.ip_address)
            return users
        except Exception as e:
            logger.error("Failed to get users from %s: %s", self.ip_address, e)
            return []

    def get_attendance(self) -> List[AttendanceRecord]:
        """Get all attendance records from the device with retry and verification.

        Reads the expected record count from the device first, then fetches
        attendance data. If the fetch returns fewer records than expected,
        retries up to 3 times. Logs warnings when data appears incomplete.

        Returns:
            List of AttendanceRecord objects.
        """
        if not self._conn:
            return []

        expected_count = self._get_expected_attendance_count()
        max_attempts = 3
        best_records: List[AttendanceRecord] = []

        for attempt in range(1, max_attempts + 1):
            try:
                raw_attendance = self._conn.get_attendance()
                records = self._parse_raw_attendance(raw_attendance)

                logger.info(
                    "Attempt %d/%d: Retrieved %d/%d attendance records from %s (%s)",
                    attempt,
                    max_attempts,
                    len(records),
                    expected_count,
                    self.ip_address,
                    self._connected_protocol,
                )

                if len(records) > len(best_records):
                    best_records = records

                # Success: got enough records
                if expected_count > 0 and len(records) >= expected_count * MIN_FETCH_RATIO:
                    break

                # No expected count available but got records - accept it
                if expected_count == 0 and len(records) > 0:
                    break

                # Low count - retry
                if attempt < max_attempts:
                    logger.warning(
                        "Incomplete fetch from %s (%d/%d), retrying in 3s...",
                        self.ip_address,
                        len(records),
                        expected_count,
                    )
                    time.sleep(3)

            except Exception as e:
                logger.error(
                    "Attempt %d/%d failed getting attendance from %s: %s",
                    attempt,
                    max_attempts,
                    self.ip_address,
                    e,
                )
                if attempt < max_attempts:
                    time.sleep(3)

        if expected_count > 0 and len(best_records) < expected_count * 0.9:
            logger.warning(
                "INCOMPLETE DATA from %s: got %d of %d expected records (%.1f%%)",
                self.ip_address,
                len(best_records),
                expected_count,
                len(best_records) / expected_count * 100,
            )

        return best_records

    def _get_expected_attendance_count(self) -> int:
        """Read expected attendance record count from device memory.

        Returns:
            Expected number of attendance records, or 0 if unknown.
        """
        try:
            self._conn.read_sizes()
            count = getattr(self._conn, "records", 0)
            logger.info(
                "Device %s reports %d attendance records in memory",
                self.ip_address,
                count,
            )
            return count
        except Exception as e:
            logger.warning(
                "Could not read sizes from %s: %s", self.ip_address, e
            )
            return 0

    def _parse_raw_attendance(
        self, raw_attendance: list
    ) -> List[AttendanceRecord]:
        """Parse raw pyzk attendance objects into our AttendanceRecord model.

        Args:
            raw_attendance: List of pyzk Attendance objects.

        Returns:
            List of parsed AttendanceRecord objects.
        """
        records = []
        for a in raw_attendance:
            try:
                user_id = a.user_id if hasattr(a, "user_id") else int(a.uid)
                records.append(
                    AttendanceRecord(
                        user_id=user_id,
                        timestamp=a.timestamp,
                        status=a.status if hasattr(a, "status") else 0,
                        punch=a.punch if hasattr(a, "punch") else 0,
                        uid=f"{self.ip_address}_{user_id}_{a.timestamp.isoformat()}",
                    )
                )
            except Exception as e:
                logger.warning("Failed to parse attendance record: %s", e)
        return records

    def get_fingerprints(self) -> List[Fingerprint]:
        """Get all fingerprint templates from the device.

        Returns:
            List of Fingerprint objects.
        """
        if not self._conn:
            return []

        fingerprints = []

        try:
            users = self._conn.get_users()

            for user in users:
                user_id = user.user_id if hasattr(user, "user_id") else int(user.uid)

                for finger_id in range(10):
                    try:
                        template = self._conn.get_user_template(
                            uid=user.uid, temp_id=finger_id
                        )
                        if template and template.template:
                            fingerprints.append(
                                Fingerprint(
                                    user_id=user_id,
                                    finger_id=finger_id,
                                    template=template.template,
                                )
                            )
                    except Exception:
                        continue

            logger.info(
                "Retrieved %d fingerprints from %s",
                len(fingerprints),
                self.ip_address,
            )
            return fingerprints
        except Exception as e:
            logger.error(
                "Failed to get fingerprints from %s: %s", self.ip_address, e
            )
            return []

    def get_all_data(
        self,
    ) -> Tuple[
        Optional[DeviceInfo], List[User], List[AttendanceRecord], List[Fingerprint]
    ]:
        """Get all data from the device in a single operation.

        Disables the device during extraction to prevent interference.
        If the initial attendance fetch appears incomplete and the connection
        is using UDP, automatically retries with TCP for more reliable
        chunked data transfer.

        Returns:
            Tuple of (DeviceInfo, users, attendance, fingerprints).
        """
        device_info = None
        users: List[User] = []
        attendance: List[AttendanceRecord] = []
        fingerprints: List[Fingerprint] = []

        if not self.connect():
            return (device_info, users, attendance, fingerprints)

        try:
            self.disable_device()

            device_info = self.get_device_info()
            users = self.get_users()

            # Get expected attendance count before fetching
            expected_count = self._get_expected_attendance_count()

            attendance = self.get_attendance()

            # If fetch seems incomplete and we used UDP, retry with TCP
            if (
                expected_count > 0
                and len(attendance) < expected_count * MIN_FETCH_RATIO
                and self._connected_protocol == "UDP"
            ):
                logger.warning(
                    "Incomplete attendance on UDP (%d/%d) from %s, retrying with TCP...",
                    len(attendance),
                    expected_count,
                    self.ip_address,
                )
                self.enable_device()
                self.disconnect()

                # Reconnect forcing TCP
                original_force_udp = self.force_udp
                self.force_udp = False

                if self._try_connect(force_udp=False):
                    try:
                        self.disable_device()
                        tcp_attendance = self.get_attendance()

                        if len(tcp_attendance) > len(attendance):
                            logger.info(
                                "TCP retry improved: %d records (was %d on UDP) from %s",
                                len(tcp_attendance),
                                len(attendance),
                                self.ip_address,
                            )
                            attendance = tcp_attendance
                        else:
                            logger.info(
                                "TCP retry got %d records (same or fewer than UDP %d) from %s",
                                len(tcp_attendance),
                                len(attendance),
                                self.ip_address,
                            )

                        self.enable_device()
                    except Exception as e:
                        logger.error(
                            "TCP retry failed for %s: %s", self.ip_address, e
                        )
                else:
                    logger.warning(
                        "Could not reconnect via TCP to %s, using UDP results",
                        self.ip_address,
                    )

                self.force_udp = original_force_udp
            else:
                self.enable_device()

        except Exception as e:
            logger.error(
                "Error during data extraction from %s: %s", self.ip_address, e
            )
            try:
                self.enable_device()
            except Exception:
                pass
        finally:
            self.disconnect()

        logger.info(
            "Final data from %s: %d users, %d attendance records",
            self.ip_address,
            len(users),
            len(attendance),
        )

        return (device_info, users, attendance, fingerprints)
