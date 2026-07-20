BROOKER RIDGE FORMS — EDITING GUIDE (v2.0)

Install: WordPress Dashboard > Plugins > Add New Plugin > Upload Plugin, choose the ZIP, then Activate.

Shortcodes:
  [brooker_appointment_form]
  [brooker_registration_form]

After activation, replace each Jotform iframe/code module with the matching shortcode in a Divi Text or Code module.

Where to edit:
  WordPress Dashboard > Settings > Brooker Ridge Form Editor — edit fields, labels, types, choices, required status, layout, ordering, visibility, and conditional logic.
  WordPress Dashboard > Settings > Brooker Ridge Forms — change colours, width, corner roundness, and Google Sheets connection.
  brooker-ridge-forms.php — questions, choices, required fields, notification email, and submission processing.
  assets/forms.css — colours, spacing, typography, mobile layout, and button styling.
  assets/forms.js — conditional-field display logic and submit state.

GOOGLE SHEETS SETUP
1. Sign in to brah.reception@gmail.com and open https://script.google.com.
2. Create a new project and paste the contents of google-apps-script.js.
3. Replace REPLACE_WITH_YOUR_GOOGLE_SPREADSHEET_ID with the ID from the clinic Sheet URL, and replace REPLACE_WITH_A_LONG_PRIVATE_SECRET with a long random private phrase.
4. Deploy > New deployment > Web app. Execute as Me. Access: Anyone.
5. Copy the Web App URL ending in /exec.
6. In WordPress, open Settings > Brooker Ridge Forms. Paste the Web App URL and the exact same private secret, then Save.
7. Submit one test form and confirm that a row appears in the correct tab of the Google Sheet.

Security included: WordPress nonce, signed and time-limited human-confirmation token, checkbox confirmation, hidden honeypot, minimum completion time, 30-second IP throttling, validation, upload size limit, and sanitization.

CONDITIONAL LOGIC
In the Form Editor, use "Show only when" to choose a controlling question, then enter the exact answer under "Equals". Example: Pet Information 2 fields show only when Register Another Pet? equals Yes. Hidden conditional fields are disabled and are not treated as required until shown.

Before using live: submit both forms once and verify delivery to brah.reception@gmail.com. For best delivery reliability, configure authenticated SMTP in WordPress.
