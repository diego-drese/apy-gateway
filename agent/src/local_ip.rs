use std::net::{IpAddr, UdpSocket};

/// Self-detects the replica's outbound-facing local IP for the heartbeat payload (Fase 10,
/// SPEC.md §12) — `replica_agents.ip_address` is a required column and nothing else reports it.
/// `UdpSocket::connect` never actually sends a packet (UDP is connectionless) — it just asks the
/// OS routing table which local interface/address it would use to reach `probe_addr`, so this is
/// cheap and side-effect-free. Uses only `std`, matching this crate's "zero new dependencies"
/// value (CHANGELOG.md, Fase 9) — read once at boot (the answer is invariant for a container's
/// lifetime), not on every heartbeat tick.
pub fn detect_local_ip(probe_addr: &str) -> std::io::Result<IpAddr> {
    let socket = UdpSocket::bind("0.0.0.0:0")?;
    socket.connect(probe_addr)?;
    Ok(socket.local_addr()?.ip())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn detects_loopback_when_probing_loopback() {
        let ip = detect_local_ip("127.0.0.1:9").unwrap();
        assert_eq!(ip, "127.0.0.1".parse::<IpAddr>().unwrap());
    }
}
