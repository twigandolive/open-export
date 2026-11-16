"""Throwaway FTP server for Open Export tests. Binds to 127.0.0.1 only.

Usage: ftp_server.py <root> <port> <user> <password> [<certfile>]
With a certfile (PEM with key and certificate) the server speaks explicit FTPS.
Prints READY on stdout when it accepts connections.
"""
import sys

from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import FTPHandler
from pyftpdlib.servers import FTPServer

root, port, user, password = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4]
auth = DummyAuthorizer()
auth.add_user(user, password, root, perm="elradfmw")
handler = FTPHandler
if len(sys.argv) > 5:
    from pyftpdlib.handlers import TLS_FTPHandler

    handler = TLS_FTPHandler
    handler.certfile = sys.argv[5]
handler.authorizer = auth
handler.passive_ports = range(port + 1, port + 40)
handler.log_prefix = ""
server = FTPServer(("127.0.0.1", port), handler)
print("READY", flush=True)
server.serve_forever()
