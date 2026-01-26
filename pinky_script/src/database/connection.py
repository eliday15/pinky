"""Database connection management for MySQL."""

import logging
from pathlib import Path
from typing import Optional

import mysql.connector
from mysql.connector import Error, MySQLConnection
from mysql.connector.pooling import PooledMySQLConnection

from src.config import MySQLConfig

logger = logging.getLogger(__name__)


class DatabaseConnection:
    """Manages MySQL database connections and operations."""

    def __init__(self, config: MySQLConfig) -> None:
        """Initialize database connection manager.

        Args:
            config: MySQL configuration object.
        """
        self.config = config
        self._connection: Optional[MySQLConnection | PooledMySQLConnection] = None

    def connect(self) -> MySQLConnection | PooledMySQLConnection:
        """Establish connection to MySQL database.

        Returns:
            Active MySQL connection.

        Raises:
            Error: If connection fails.
        """
        if self._connection is not None and self._connection.is_connected():
            return self._connection

        try:
            self._connection = mysql.connector.connect(
                host=self.config.host,
                port=self.config.port,
                user=self.config.user,
                password=self.config.password,
                database=self.config.database,
                autocommit=False,
            )
            logger.info(
                "Connected to MySQL database: %s@%s:%d/%s",
                self.config.user,
                self.config.host,
                self.config.port,
                self.config.database,
            )
            return self._connection
        except Error as e:
            logger.error("Failed to connect to MySQL: %s", e)
            raise

    def disconnect(self) -> None:
        """Close the database connection."""
        if self._connection is not None and self._connection.is_connected():
            self._connection.close()
            logger.info("Disconnected from MySQL database")
            self._connection = None

    def init_schema(self) -> None:
        """Initialize database schema by executing schema.sql.

        Raises:
            Error: If schema initialization fails.
        """
        schema_path = Path(__file__).parent / "schema.sql"

        if not schema_path.exists():
            raise FileNotFoundError(f"Schema file not found: {schema_path}")

        schema_sql = schema_path.read_text()

        conn = self.connect()
        cursor = conn.cursor()

        try:
            for statement in schema_sql.split(";"):
                statement = statement.strip()
                if statement:
                    cursor.execute(statement)
            conn.commit()
            logger.info("Database schema initialized successfully")
        except Error as e:
            conn.rollback()
            logger.error("Failed to initialize schema: %s", e)
            raise
        finally:
            cursor.close()

    def execute(
        self, query: str, params: Optional[tuple] = None, commit: bool = True
    ) -> int:
        """Execute a single SQL statement.

        Args:
            query: SQL query to execute.
            params: Query parameters.
            commit: Whether to commit the transaction.

        Returns:
            Number of affected rows or last insert ID.
        """
        conn = self.connect()
        cursor = conn.cursor()

        try:
            cursor.execute(query, params)
            if commit:
                conn.commit()
            result = cursor.lastrowid if cursor.lastrowid else cursor.rowcount
            return result
        except Error as e:
            if commit:
                conn.rollback()
            logger.error("Query execution failed: %s", e)
            raise
        finally:
            cursor.close()

    def execute_many(
        self, query: str, params_list: list, commit: bool = True
    ) -> int:
        """Execute a SQL statement with multiple parameter sets.

        Args:
            query: SQL query to execute.
            params_list: List of parameter tuples.
            commit: Whether to commit the transaction.

        Returns:
            Number of affected rows.
        """
        if not params_list:
            return 0

        conn = self.connect()
        cursor = conn.cursor()

        try:
            cursor.executemany(query, params_list)
            if commit:
                conn.commit()
            return cursor.rowcount
        except Error as e:
            if commit:
                conn.rollback()
            logger.error("Batch execution failed: %s", e)
            raise
        finally:
            cursor.close()

    def fetch_one(
        self, query: str, params: Optional[tuple] = None
    ) -> Optional[tuple]:
        """Fetch a single row from the database.

        Args:
            query: SQL query to execute.
            params: Query parameters.

        Returns:
            Single row tuple or None.
        """
        conn = self.connect()
        cursor = conn.cursor()

        try:
            cursor.execute(query, params)
            return cursor.fetchone()
        finally:
            cursor.close()

    def fetch_all(self, query: str, params: Optional[tuple] = None) -> list:
        """Fetch all rows from the database.

        Args:
            query: SQL query to execute.
            params: Query parameters.

        Returns:
            List of row tuples.
        """
        conn = self.connect()
        cursor = conn.cursor()

        try:
            cursor.execute(query, params)
            return cursor.fetchall()
        finally:
            cursor.close()
