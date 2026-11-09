systemd-Timer für die Installation OHNE Docker (technische Doku, Abschnitt
"Alternative: Installation ohne Docker"). Installation:

  sudo cp kalender-*.service kalender-*.timer /etc/systemd/system/
  sudo systemctl daemon-reload
  sudo systemctl enable --now kalender-import.timer kalender-mail.timer \
       kalender-monitor.timer kalender-cleanup.timer
  journalctl -u kalender-import
