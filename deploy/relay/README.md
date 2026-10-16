# Relay layout

```
visitor ──TLS 1.3──> relay (public IP, DNS A/AAAA)  ──WireGuard 10.77.0.0/24──> origin (10.77.0.2)
```

* DNS points only at the relay. The origin has no public DNS name and its firewall
  accepts nothing except WireGuard (UDP) from the relay and SSH from your admin address.
* The relay forwards TCP 80/443 untouched (`nginx-stream.conf`), so TLS terminates on the
  origin. Certificates are obtained there with DNS-01 (no inbound port 80 needed on origin).
* Because the relay opens the connection to the origin, the origin sees the relay's tunnel
  address (10.77.0.1) for every visitor. LockSend therefore has no visitor IP to log, and
  per-visitor rate limiting on the origin degrades to a global limit; set
  `limit_req_zone` conservatively or move per-IP limits onto the relay if you need them
  (relay-side limiting must not log).
* Do not send mail from the origin, do not host other sites on it, and do not let it make
  outbound requests that reveal its address (no OCSP stapling fetch from a public IP:
  `ssl_stapling` is left off in the shipped config). Use the relay as the origin's egress if
  outbound traffic is unavoidable.
* Certificate Transparency logs list hostnames, not IPs, so the origin address is not leaked there.
* Check with `scripts/audit_privacy.sh --origin-ip <ip>` plus a public scan (Censys/Shodan)
  that the origin does not answer on the public Internet.
