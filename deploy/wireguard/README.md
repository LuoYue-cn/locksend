Generate keys on each host: `umask 077; wg genkey | tee private | wg pubkey > public`, and one
shared preset key with `wg genpsk`. Fill the placeholders, then `systemctl enable --now wg-quick@wg0`
on both ends and load `origin-firewall.nft` on the origin. Verify from the origin with
`wg show` and from outside with `nmap -Pn <origin-ip>` (every port must be filtered).
