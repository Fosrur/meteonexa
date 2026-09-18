<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/SmtpMailer.php';
require_once dirname(__DIR__) . '/email_templates.php';
assert_same_origin();
require_method('POST');
$config = load_config();
$pdo = meteonexa_db($config);
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE']??''));
$isMultipart = str_starts_with($contentType, 'multipart/form-data');
$contentLength = max(0, (int)($_SERVER['CONTENT_LENGTH']??0));
if ($isMultipart&&$contentLength > 17 * 1024 * 1024)respond(['ok'=>false, 'code'=>'PAYLOAD_TOO_LARGE', 'message'=>'bug.api.attachments_too_large'], 413);
$data = $isMultipart ? $_POST : input_json();
if (!is_array($data))$data =[];
$feedback = (array)($config['feedback']??[]);
$ipLimit = max(2, min(30, (int)($feedback['max_per_ip_hour']??6)));
$globalLimit = max(20, min(2000, (int)($feedback['max_global_hour']??180)));
require_ip_rate_limit($pdo, 'bug_report_ip', $ipLimit, 3600);
require_global_rate_limit($pdo, 'bug_report_global', $globalLimit, 3600);
// Silent honeypot. The payload is intentionally discarded without revealing
// to automated clients that a trap was hit.
if (trim((string)($data['website']??''))!=='')respond(['ok'=>true, 'reportId'=>'']);
$categoryKeys =['overview'=>'bug.category.overview', 'alerts'=>'bug.category.alerts', 'radar'=>'bug.category.radar', 'intelligence'=>'bug.category.intelligence', 'account'=>'bug.category.account', 'performance'=>'bug.category.performance', 'privacy'=>'bug.category.privacy', 'other'=>'bug.category.other',];
$category = strtolower(clean_text($data['category']??'', 32, ''));
$categoryOther = clean_text($data['categoryOther']??'', 100, '');
if (!array_key_exists($category, $categoryKeys))respond(['ok'=>false, 'code'=>'INVALID_CATEGORY', 'message'=>'bug.api.invalid_category'], 422);
if ($category==='other'&&meteonexa_text_length($categoryOther) < 3)respond(['ok'=>false, 'code'=>'INVALID_CATEGORY', 'message'=>'bug.api.invalid_category'], 422);
$title = clean_text($data['title']??'', 120);
$description = clean_text($data['description']??'', 5000);
$steps = clean_text($data['steps']??'', 3000);
$contactEmail = strtolower(trim((string)($data['contactEmail']??'')));
$language = strtolower(clean_text($data['language']??'it', 8, 'it'));
if (!in_array($language,['it', 'en', 'fr', 'es', 'de'], true))$language = 'it';
$categoryLabel = $category==='other' ? $categoryOther : meteonexa_backend_text($categoryKeys[$category],[], $language);
if (meteonexa_text_length($title) < 5)respond(['ok'=>false, 'code'=>'INVALID_TITLE', 'message'=>'bug.api.invalid_title'], 422);
if (meteonexa_text_length($description) < 20)respond(['ok'=>false, 'code'=>'INVALID_DESCRIPTION', 'message'=>'bug.api.invalid_description'], 422);
if ($contactEmail!==''&&(strlen($contactEmail) > 254||filter_var($contactEmail, FILTER_VALIDATE_EMAIL)===false))respond(['ok'=>false, 'code'=>'INVALID_CONTACT_EMAIL', 'message'=>'bug.api.invalid_email'], 422);
$diagnosticsInput = $data['diagnostics']??null;
if (is_string($diagnosticsInput)&&$diagnosticsInput!=='') {
    $decoded = json_decode($diagnosticsInput, true);
    $diagnosticsInput = is_array($decoded) ? $decoded : null;
}
$diagnostics = null;
if (is_array($diagnosticsInput)) {
    $raw = $diagnosticsInput;
    $diagnostics =['build'=>clean_text($raw['build']??'', 32), 'page'=>clean_text($raw['page']??'', 40), 'session'=>in_array((string)($raw['session']??''),['guest', 'authenticated'], true) ? (string)$raw['session'] : 'unknown', 'language'=>clean_text($raw['language']??'', 12), 'theme'=>clean_text($raw['theme']??'', 16), 'online'=>(bool)($raw['online']??false), 'standalone'=>(bool)($raw['standalone']??false), 'viewport'=>preg_match('/^\d{1,5}x\d{1,5}$/', (string)($raw['viewport']??''))===1 ? (string)$raw['viewport'] : '', 'platform'=>clean_text($raw['platform']??'', 80), 'touch'=>(bool)($raw['touch']??false),];
}
// Attachments remain PHP temporary upload files and are never copied into the
// application storage/database. Content is accepted only after server-side MIME
// inspection, not from the browser-provided filename/type.
$attachments =[];
$allowedMimes =['image/jpeg'=>['jpg', 'jpeg'], 'image/png'=>['png'], 'image/webp'=>['webp'], 'video/mp4'=>['mp4'], 'video/webm'=>['webm'], 'video/quicktime'=>['mov'],];
$maxFiles = 6;
$maxImage = 8 * 1024 * 1024;
$maxVideo = 12 * 1024 * 1024;
$maxTotal = 15 * 1024 * 1024;
$totalBytes = 0;
$uploads = $_FILES['attachments']??null;
if (is_array($uploads)&&isset($uploads['name'])) {
    $names = is_array($uploads['name']) ? $uploads['name'] :[$uploads['name']];
    $tmps = is_array($uploads['tmp_name']??null) ? $uploads['tmp_name'] :[$uploads['tmp_name']??''];
    $sizes = is_array($uploads['size']??null) ? $uploads['size'] :[$uploads['size']??0];
    $errors = is_array($uploads['error']??null) ? $uploads['error'] :[$uploads['error']??UPLOAD_ERR_NO_FILE];
    if (count($names) > $maxFiles)respond(['ok'=>false, 'code'=>'TOO_MANY_ATTACHMENTS', 'message'=>'bug.api.attachments_too_large'], 413);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($names as $i=>$originalName) {
        $error = (int)($errors[$i]??UPLOAD_ERR_NO_FILE);
        if ($error===UPLOAD_ERR_NO_FILE)continue;
        if ($error!==UPLOAD_ERR_OK)respond(['ok'=>false, 'code'=>'UPLOAD_FAILED', 'message'=>'bug.api.invalid_attachment'], 422);
        $tmp = (string)($tmps[$i]??'');
        $size = (int)($sizes[$i]??0);
        if ($tmp===''||!is_uploaded_file($tmp)||$size < 1)respond(['ok'=>false, 'code'=>'INVALID_ATTACHMENT', 'message'=>'bug.api.invalid_attachment'], 422);
        $mime = strtolower((string)$finfo->file($tmp));
        if (!isset($allowedMimes[$mime]))respond(['ok'=>false, 'code'=>'INVALID_ATTACHMENT_TYPE', 'message'=>'bug.api.invalid_attachment'], 422);
        $limit = str_starts_with($mime, 'image/') ? $maxImage : $maxVideo;
        if ($size > $limit)respond(['ok'=>false, 'code'=>'ATTACHMENT_TOO_LARGE', 'message'=>'bug.api.attachment_too_large'], 413);
        $totalBytes+=$size;
        if ($totalBytes > $maxTotal)respond(['ok'=>false, 'code'=>'ATTACHMENTS_TOO_LARGE', 'message'=>'bug.api.attachments_too_large'], 413);
        $base = basename(str_replace('\\', '/', (string)$originalName));
        $base = preg_replace('/[^\pL\pN._ -]+/u', '_', $base) ? : 'attachment';
        $base = meteonexa_text_substr($base, 0, 100);
        $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $stem = trim((string)pathinfo($base, PATHINFO_FILENAME), ' ._-') ? : 'attachment';
        $trustedExtension = in_array($extension, $allowedMimes[$mime], true) ? $extension : $allowedMimes[$mime][0];
        $base = meteonexa_text_substr($stem, 0, 90) . '.' . $trustedExtension;
        $attachments[] =['path'=>$tmp, 'name'=>$base, 'mime'=>$mime, 'size'=>$size];
    }
}
// SMTP remains DB-authoritative for transport. The destination is resolved only
// server-side: a dedicated support inbox wins, then the public privacy contact,
// then the SMTP sender mailbox as a backward-compatible fallback.
try {
    if (!smtp_is_configured($config))meteonexa_provision_smtp_if_missing($config);
} catch (Throwable $provisionError) {
    meteonexa_log_event('bug_report_smtp_provision_failed', $provisionError);
}
$dbSmtp = meteonexa_load_smtp($config);
if ($dbSmtp!==[])$config['smtp'] = array_replace((array)($config['smtp']??[]), $dbSmtp);
$smtp = (array)($config['smtp']??[]);
$recipient = '';
$recipientCandidates =[trim((string)($feedback['recipient_email']??'')), trim((string)(($config['legal']['privacy_contact_email']??''))), trim((string)($smtp['from_email']??'')),];
foreach ($recipientCandidates as $candidate) {
    if ($candidate!==''&&filter_var($candidate, FILTER_VALIDATE_EMAIL)!==false) {
        $recipient = $candidate;
        break;
    }
}
if ($recipient==='')respond(['ok'=>false, 'code'=>'EMAIL_TRANSPORT_UNAVAILABLE', 'message'=>'bug.api.transport_unavailable'], 503);
$nativeMailEnabled = (bool)($smtp['native_mail_fallback']??false)&&function_exists('mail');
$smtpUsable = trim((string)($smtp['host']??''))!==''&&filter_var(trim((string)($smtp['from_email']??'')), FILTER_VALIDATE_EMAIL)!==false&&(trim((string)($smtp['username']??''))===''||(string)($smtp['password']??'')!=='');
if (!$smtpUsable&&!$nativeMailEnabled)respond(['ok'=>false, 'code'=>'EMAIL_TRANSPORT_UNAVAILABLE', 'message'=>'bug.api.transport_unavailable'], 503);
$reportId = 'BUG-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
$createdAt = gmdate('c');
$subjectTitle = meteonexa_text_substr(trim((string)preg_replace('/[\r\n]+/', ' ', $title)), 0, 80);
$appName = trim((string)($config['app']['name']??'MeteoNexa')) ? : 'MeteoNexa';
// Operators may optionally define bug_report_<category> rows in the DB. If
// absent, the generic bug_report template remains the safe fallback. This keeps
// email copy editable without a deploy while allowing section-specific wording.
$template = meteonexa_email_template($pdo, 'bug_report_' . $category, $language,['report_id'=>$reportId, 'title'=>$subjectTitle, 'section'=>$categoryLabel, 'app_name'=>$appName,], 'bug_report');
$e = static fn(string $value) : string=>htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$block = static fn(string $label, string $value) : string=>'<div style="margin:0 0 16px"><div style="margin:0 0 6px;color:#58718a;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div><div style="padding:13px 15px;border:1px solid #dce8f1;border-radius:13px;background:#f7fafc;color:#17324a;font-size:14px;line-height:1.6">' . nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</div></div>';
$emailText = static fn(string $key, array $params =[]) : string=>meteonexa_backend_text($key, $params, $language);
$labels =['id'=>$emailText('bug.email.id'), 'date'=>$emailText('bug.email.utc_date'), 'category'=>$emailText('bug.field.category'), 'title'=>$emailText('bug.field.title'), 'description'=>$emailText('bug.field.description'), 'steps'=>$emailText('bug.field.steps'), 'contact'=>$emailText('bug.field.email'), 'attachments'=>$emailText('bug.section.attachments.title'), 'diagnostics'=>$emailText('bug.diagnostics.title'),];
$notProvided = $emailText('bug.email.not_provided');
$notSpecified = $emailText('bug.email.not_specified');
$yes = $emailText('bug.email.yes');
$no = $emailText('bug.email.no');
$contentHtml = '<div style="margin:0 0 18px;padding:12px 14px;border-radius:13px;background:#edf8fc;color:#087f92;font-size:13px;font-weight:800">' . $e($reportId) . ' · ' . $e($createdAt) . '</div>' . $block($labels['category'], $categoryLabel) . $block($labels['title'], $subjectTitle) . $block($labels['description'], $description) . $block($labels['steps'], $steps!=='' ? $steps : $notSpecified) . $block($labels['contact'], $contactEmail!=='' ? $contactEmail : $notProvided);
if ($attachments!==[]) {
    $rows =[];
    foreach ($attachments as $attachment)$rows[] = $attachment['name'] . ' · ' . round(((int)$attachment['size']) / 1048576, 2) . ' MB';
    $contentHtml.=$block($labels['attachments'], implode("\n", $rows));
}
$diagnosticLabels =['build'=>$emailText('bug.email.diagnostic.build'), 'page'=>$emailText('bug.email.diagnostic.page'), 'session'=>$emailText('bug.email.diagnostic.session'), 'language'=>$emailText('bug.email.diagnostic.language'), 'theme'=>$emailText('bug.email.diagnostic.theme'), 'online'=>$emailText('bug.email.diagnostic.online'), 'standalone'=>$emailText('bug.email.diagnostic.standalone'), 'viewport'=>$emailText('bug.email.diagnostic.viewport'), 'platform'=>$emailText('bug.email.diagnostic.platform'), 'touch'=>$emailText('bug.email.diagnostic.touch'),];
$diagnosticValue = static function(string $key, mixed $value)use($yes, $no, $emailText) : string {
    if (is_bool($value))return $value ? $yes : $no;
    if ($key==='session') {
        $session = strtolower(trim((string)$value));
        if (in_array($session,['guest', 'authenticated', 'unknown'], true))return $emailText('bug.email.session.' . $session);
    }
    return (string)$value;
};
if (is_array($diagnostics)) {
    $rows =[];
    foreach ($diagnostics as $key=>$value) {
        $value = $diagnosticValue((string)$key, $value);
        if ($value==='')continue;
        $rows[] =($diagnosticLabels[(string)$key]??(string)$key) . ': ' . $value;
    }
    if ($rows)$contentHtml.=$block($labels['diagnostics'], implode("\n", $rows));
}
$contentPlain = $labels['id'] . ": {$reportId}\n" . $labels['date'] . ": {$createdAt}\n" . $labels['category'] . ": {$categoryLabel}\n" . $labels['title'] . ": {$subjectTitle}\n\n" . $labels['description'] . ":\n{$description}\n\n" . $labels['steps'] . ":\n" .($steps!=='' ? $steps : $notSpecified) . "\n\n" . $labels['contact'] . ": " .($contactEmail!=='' ? $contactEmail : $notProvided);
if ($attachments!==[]) {
    $contentPlain.="\n\n" . $labels['attachments'] . ":";
    foreach ($attachments as $a)$contentPlain.="\n- {$a['name']} ({$a['mime']}, {$a['size']} B)";
}
if (is_array($diagnostics)) {
    $contentPlain.="\n\n" . $labels['diagnostics'] . ":";
    foreach ($diagnostics as $k=>$v) {
        $v = $diagnosticValue((string)$k, $v);
        if ($v==='')continue;
        $contentPlain.="\n" .($diagnosticLabels[(string)$k]??(string)$k) . ": {$v}";
    }
}
$mail = meteonexa_render_branded_email($template, $appName, $language, $contentHtml, $contentPlain);
$mailAttachments = array_map(static fn(array $a) : array=>['path'=>$a['path'], 'name'=>$a['name'], 'mime'=>$a['mime']], $attachments);
$logo = dirname(__DIR__, 2) . '/assets/icons/icon-192.png';
try {
    if (trim((string)($smtp['from_name']??''))==='')$smtp['from_name'] = $appName;
    if ($smtpUsable) {
        try {
            (new SmtpMailer($smtp))->sendHtml($recipient, $mail['subject'], $mail['html'], $mail['plain'], $logo, $mailAttachments);
            respond(['ok'=>true, 'reportId'=>$reportId, 'transport'=>'smtp']);
        } catch (Throwable $smtpError) {
            if (!$nativeMailEnabled)throw $smtpError;
            meteonexa_log_event('bug_report_smtp_fallback', $smtpError);
        }
    }
    if ($nativeMailEnabled) {
        SmtpMailer::sendNativeHtml($smtp, $recipient, $mail['subject'], $mail['html'], $mail['plain'], $logo, $mailAttachments);
        respond(['ok'=>true, 'reportId'=>$reportId, 'transport'=>'mail']);
    }
    throw new RuntimeException('EMAIL_TRANSPORT_UNAVAILABLE');
} catch (Throwable $error) {
    meteonexa_log_event('bug_report_send_failed', $error);
    respond(['ok'=>false, 'code'=>'BUG_REPORT_SEND_FAILED', 'message'=>'bug.api.send_failed'], 503);
}
