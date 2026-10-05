-- A separate database for the test suite, so RefreshDatabase never touches dev data.
CREATE DATABASE IF NOT EXISTS tally_testing;
GRANT ALL PRIVILEGES ON tally_testing.* TO 'tally'@'%';
