"""Stop Supervisor if either required child exhausts startup retries.

Supervisor otherwise stays alive with a FATAL child. Normal SIGTERM shutdown is
handled by Supervisor, which sends QUIT to Nginx and PHP-FPM.
"""
import os
import signal
import sys

from supervisor import childutils

while True:
    headers, payload = childutils.listener.wait(sys.stdin, sys.stdout)
    childutils.listener.ok(sys.stdout)
    if headers["eventname"] == "PROCESS_STATE_FATAL":
        sys.stderr.write("Required container process failed; stopping supervisor.\n")
        sys.stderr.flush()
        with open("/var/run/nginx/required-process-failed", "w", encoding="utf-8") as failure:
            failure.write("required process exhausted startup retries\n")
        os.kill(os.getppid(), signal.SIGTERM)
        break
