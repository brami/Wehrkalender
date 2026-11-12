systemd-Timer für die Installation OHNE Docker (technische Doku, Abschnitt
"Alternative: Installation ohne Docker"). Installation:

  sudo cp wehrkalender-*.service wehrkalender-*.timer /etc/systemd/system/
  sudo systemctl daemon-reload
  sudo systemctl enable --now wehrkalender-import.timer wehrkalender-mail.timer \
       wehrkalender-monitor.timer wehrkalender-cleanup.timer
  journalctl -u wehrkalender-import
