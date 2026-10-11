"""Agent-authored semantic proposal. Unblinded demonstration, not a benchmark.
This file is the frozen output of judgment, not a deterministic inference rule.
"""
import json
from generate import EVIDENCE, generate

# Event -> (object constraint, verb, headline, object expression).
choices = {
'ClientCreated': ('*','client.created',':object was added','client'),
'ClientRenamed': ('*','client.renamed',':object was renamed','client'),
'ComplianceDocumentRecorded': ('*','client.compliance_document_recorded','Compliance paperwork was recorded for :object','document->client'),
'ComplianceDocumentSuperseded': ('*','client.compliance_document_superseded','Compliance paperwork for :object was replaced','superseded->client'),
'ClauseAddedToLibrary': ('ClauseTemplate','add',':object was added to the clause library','clause'),
'ClauseRestoredToLibrary': ('ClauseTemplate','restore',':object was restored to the clause library','clause'),
'ClauseRetiredFromLibrary': ('ClauseTemplate','retire',':object was retired from the clause library','clause'),
'ClauseRewordedInLibrary': ('ClauseTemplate','update','The wording of :object was changed in the clause library','clause'),
'ClauseVariantRemoved': ('ClauseVariant','remove',':object was removed','variant'),
'ClauseVariantRestored': ('ClauseVariant','restore',':object was restored','variant'),
'ClauseVariantReworded': ('ClauseVariant','update','The wording of :object was changed','variant'),
'ClauseVariantWritten': ('ClauseVariant','create',':object was written','variant'),
'DocumentAcceptanceRecorded': ('*','document.accepted','Acceptance of :object was recorded','document'),
'DocumentComposed': ('*','document.composed',':object was drawn up','document'),
'DocumentDeclineRecorded': ('*','document.declined','A decline of :object was recorded','document'),
'DocumentEmailBounced': ('*','document.email_bounced','An email carrying :object bounced','document'),
'DocumentEmailComplained': ('*','document.email_complained','An email carrying :object was reported as spam','document'),
'DocumentEmailDelivered': ('*','document.email_delivered','A recipient mail server accepted an email carrying :object','document'),
'DocumentEmailFailed': ('*','document.email_failed','An attempt to email :object failed','document'),
'DocumentEmailed': ('*','document.emailed',':actor emailed :object','document'),
'DocumentExecutedArchived': ('*','document.executed_archived','An executed copy of :object was archived','agreement'),
'DocumentOpenedByRecipient': ('*','document.opened','A document link recorded :object','interaction'),
'DocumentPdfDownloadedByRecipient': ('*','document.pdf_downloaded','A PDF download link recorded :object','interaction'),
'DocumentPresentationChanged': ('*','document.presentation_changed','The presentation of :object was changed','document'),
'DocumentReadied': ('*','document.readied',':object was readied','document'),
'DocumentRevisionsSquashed': ('*','document.revisions_squashed','Revisions of :object were combined','document'),
'DocumentSent': ('*','document.sent',':object was marked as sent','document'),
'DocumentSuperseded': ('*','document.superseded',':object was superseded','document'),
'DocumentWithdrawn': ('*','document.withdrawn',':object was withdrawn','document'),
'InvoicePaymentRecorded': ('*','document.payment_recorded','A payment was recorded for :object','invoice'),
'InvoiceReminderEmailed': ('*','invoice.reminder_emailed','A reminder for :object was emailed','document'),
'PaymentIntentReported': ('*','invoice.payment_intent_reported','An intention to pay :object was reported','document'),
'SheetClauseReset': ('*','document.clause_reverted','A clause on :object was reverted to the library wording','sheet'),
'SheetClauseRestored': ('*','document.clause_reinstated','A clause was put back on :object','sheet'),
'SheetClauseReworded': ('*','document.clause_rewritten','A clause on :object was rewritten','sheet'),
'SheetClauseVetoed': ('*','document.clause_removed','A clause was removed from :object','sheet'),
'SheetRefreshedToDoctrine': ('*','document.doctrine_refreshed',':object was refreshed from the clause library','sheet'),
'TermsAccepted': ('*','terms.accepted',':object was accepted','document'),
'TermsDeclined': ('*','terms.declined',':object was declined','document'),
'EngagementBegan': ('*','engagement.began',':object began','engagement'),
'EngagementEnded': ('*','engagement.ended',':object ended','engagement'),
'EngagementProposed': ('*','engagement.proposed',':object was proposed','engagement'),
'EngagementScopeModeChanged': ('*','engagement.scope_mode_changed','The scope mode of :object was changed','engagement'),
}
inv=json.loads((EVIDENCE/'inventory.json').read_text())
rows=[]
for event in inv['classes']:
 short=event['class'].split('\\')[-1]
 if not event['file'].startswith('app/Events/') or short not in choices: continue
 obj,verb,headline,prop=choices[short]
 row=dict(object=obj,verb=verb,headline=headline,property=prop,signal='event',event=event['class'],source=event['file'],line=event['line'],confidence='agent-reviewed; unblinded')
 if short=='DocumentEmailed': row['actor']='$event->send->queuedBy'; row['headline']=':object was emailed' # no assertion that queuedBy still resolves
 rows.append(row)
generate('C',rows)
(EVIDENCE/'C-method.json').write_text(json.dumps({'blind':False,'input':'inventory.json plus selective stripped source and docs MCP','guidance':'storyfeed-boost/resources/boost/guidelines/core.blade.php','token_usage':None,'billing_cost':None,'reason':'No independently metered agent invocation; current session has answer-key exposure.','deferred':['ClientLegalNameChanged branch split','DocumentSignatureAttested status split','EngagementTermsMoved timing rule','PerimeterChanged boolean/date cases','DocuSignConnected excluded as operational setup','Inline booking, credit, pause, proposal/question moments require source investigation']},indent=2))
print('C proposals',len(rows))
