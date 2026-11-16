"""Throwaway FTP server for Open Export tests. Binds to 127.0.0.1 only.

Usage: ftp_server.py <root> <port> <user> <password>
Prints READY on stdout when it accepts connections.
"""
import sys

from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import FTPHandler
from pyftpdlib.servers import FTPServer

root, port, user, password = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4]
auth = DummyAuthorizer()
auth.add_user(user, password, root, perm="elradfmw")
FTPHandler.authorizer = auth
FTPHandler.passive_ports = range(port + 1, port + 40)
FTPHandler.log_prefix = ""
server = FTPServer(("127.0.0.1", port), FTPHandler)
print("READY", flush=True)
server.serve_forever()
