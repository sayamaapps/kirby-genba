# Genba: technische Grundlage für den Datenschutztext

(English version: [PRIVACY.md](PRIVACY.md). Die Einstellungen heißen `genba.*` bzw.
`GENBA_*`; die Namen stehen im [README](README.md).)

Dieser Baustein beschreibt die Implementierung und ist keine Rechtsberatung.
Rechtsgrundlage, Interessenabwägung und die endgültige Formulierung müssen vor der
Veröffentlichung für den konkreten Betrieb fachlich geprüft und ergänzt werden.

## Betrieb der Website — technischer Textbaustein

Unsere Website enthält im Seitenkopf eine Kennung des Programmstands, also den
Commit-Hash des Quelltextes, aus dem die Seite erzeugt wurde. Diese Kennung ist
ein technischer Vermerk für unsere eigene Prüfung nach jeder Veröffentlichung.
Sie enthält keine Angaben über Besucherinnen und Besucher. Ihr Browser baut
dafür **keine Verbindung zu einem anderen Dienst** auf: Es wird kein Skript, keine
Schriftart, keine Einbettung und kein Bild von einem fremden Server nachgeladen.

Das eingesetzte Modul setzt **keine Cookies**, verwendet **keinen Browser-Speicher**
(etwa Local Storage oder Session Storage) und erhebt **keine Daten über Besucherinnen
und Besucher** dieser Website. Es liest weder Ihre IP-Adresse noch Ihren Browser-Typ
aus.

Die Prüfwerkzeuge des Moduls laufen auf unseren Build-Servern bei der Veröffentlichung
und einmal täglich, nicht in Ihrem Browser. Dabei werden Seiten unserer eigenen Website
abgerufen. Außerdem fragt die tägliche Prüfung der Verweise öffentliche Angaben bei
YouTube, bei Digistore24 und bei den Shops ab, auf die unsere Website verweist. Übertragen
werden nur diese öffentlichen Adressen und eine Kennung des Prüfprogramms. Es werden
keine Daten von Besucherinnen und Besuchern übertragen.

Zugangsdaten für einen geschützten Vorschau-Zugang werden nur an unsere eigene Website
übertragen, nicht über eine unverschlüsselte Verbindung und nie als Befehlszeilenargument.

## Hinweise für den Betreiber

- **Keine Übertragung durch den Website-Code.** Die Kennung im Seitenkopf
  verlässt die Website nicht über eigene Anfragen. Hosting und die Zugriffsprotokolle
  des Webservers liegen außerhalb des Moduls und sind in der allgemeinen
  Datenschutzerklärung der Website zu beschreiben.
- **Prüfprotokolle.** Die Ausgaben der Prüfwerkzeuge landen im Protokoll unserer
  Build-Läufe. Sie enthalten Adressen öffentlicher Seiten, keine Zugangsdaten und
  keine Besucherdaten.
- **Keine Kennung ohne Veröffentlichung.** Die Datei mit dem Commit-Hash liegt im
  Konfigurationsordner der Website und wird nicht ins Versionsverwaltungssystem
  übernommen.
- **Schlüssel und Daten.** Es gibt keinen Zugangsschlüssel und keine Datenbank des
  Moduls.
- **Übergabe der Website.** Wer die Website an einen Kunden übergibt, prüft, ob
  weitere Dienste (Analyse, Karten, Formulare, Einbettungen) auf derselben Seite
  eingebunden sind; für diese gilt eine eigene Beschreibung. Dieses Modul gehört
  nicht dazu.
