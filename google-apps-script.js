// Brooker Ridge Website Form Responses — Google Apps Script bridge
// Paste this into script.google.com while signed in as brah.reception@gmail.com.
const SPREADSHEET_ID = 'REPLACE_WITH_YOUR_GOOGLE_SPREADSHEET_ID';
const SHARED_SECRET = 'REPLACE_WITH_A_LONG_PRIVATE_SECRET';

function doPost(e) {
  try {
    const request = JSON.parse(e.postData.contents || '{}');
    if (!request.secret || request.secret !== SHARED_SECRET) return reply({ok:false, error:'Unauthorized'});
    const d = request.submission || {};
    const tab = d.form_type === 'appointment' ? 'Appointment Requests' : 'New Client Registrations';
    const values = [d.submitted_at||'',d.form_type||'',d.owner_first||'',d.owner_last||'',d.phone||'',d.email||'',d.street||'',d.unit||'',d.city||'',d.province||'',d.postal_code||'',d.existing_client||'',d.regular_vet||'',d.pet_name||'',d.species||'',d.breed||'',d.gender||'',d.altered||'',d.age||'',d.colour||'',d.appointment_type||'',d.reason||'',d.description||'',d.issue_started||'',d.preferred_date||'',d.preferred_time||'',Array.isArray(d.symptoms)?d.symptoms.join('; '):(d.symptoms||''),d.sms_consent||'',d.another_pet||'',d.pet2_pet_name||'',d.pet2_species||'',d.pet2_breed||'',d.pet2_gender||'',d.pet2_altered||'',d.pet2_age||'',d.pet2_colour||''];
    const lock = LockService.getScriptLock(); lock.waitLock(10000);
    SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(tab).appendRow(values);
    lock.releaseLock();
    return reply({ok:true});
  } catch (error) { return reply({ok:false,error:String(error)}); }
}

function reply(value) {
  return ContentService.createTextOutput(JSON.stringify(value)).setMimeType(ContentService.MimeType.JSON);
}
