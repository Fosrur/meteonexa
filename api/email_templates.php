<?php
declare(strict_types=1);
/**
 * Database-backed email copy + a single branded renderer shared by OTP,
 * feedback and future application sections. Rows are seeded only when missing,
 * so an operator can safely edit the text in email_templates without a deploy
 * overwriting the customisation.
 */
function meteonexa_email_template_defaults() : array {
    return['it'=>['auth_otp'=>['Codice di accesso MeteoNexa', 'ACCESSO SICURO', 'Il tuo codice di accesso', 'Inserisci questo codice per accedere. È valido per {minutes} minuti.', 'Se non hai richiesto tu questo codice, ignora questa email.'], 'bug_report'=>['[MeteoNexa bug] {report_id} · {title}', 'SUPPORTO METEONEXA', 'Nuova segnalazione bug', 'È arrivata una nuova segnalazione dalla sezione {section}.', 'La segnalazione e gli eventuali allegati sono stati inviati direttamente dal server MeteoNexa.'],], 'en'=>['auth_otp'=>['MeteoNexa sign-in code', 'SECURE SIGN-IN', 'Your sign-in code', 'Enter this code to sign in. It is valid for {minutes} minutes.', 'If you did not request this code, ignore this email.'], 'bug_report'=>['[MeteoNexa bug] {report_id} · {title}', 'METEONEXA SUPPORT', 'New bug report', 'A new report was sent from the {section} section.', 'The report and any attachments were sent directly by the MeteoNexa server.'],], 'fr'=>['auth_otp'=>["Code d’accès MeteoNexa", 'CONNEXION SÉCURISÉE', "Votre code d’accès", 'Saisissez ce code pour vous connecter. Il est valable {minutes} minutes.', "Si vous n’avez pas demandé ce code, ignorez cet e-mail."], 'bug_report'=>['[Bug MeteoNexa] {report_id} · {title}', 'ASSISTANCE METEONEXA', 'Nouveau signalement de bug', 'Un nouveau signalement a été envoyé depuis la section {section}.', 'Le signalement et les éventuelles pièces jointes ont été envoyés directement par le serveur MeteoNexa.'],], 'es'=>['auth_otp'=>['Código de acceso de MeteoNexa', 'ACCESO SEGURO', 'Tu código de acceso', 'Introduce este código para acceder. Es válido durante {minutes} minutos.', 'Si no solicitaste este código, ignora este correo.'], 'bug_report'=>['[Bug MeteoNexa] {report_id} · {title}', 'SOPORTE METEONEXA', 'Nuevo informe de error', 'Se ha enviado un nuevo informe desde la sección {section}.', 'El informe y los posibles archivos adjuntos fueron enviados directamente por el servidor MeteoNexa.'],], 'de'=>['auth_otp'=>['MeteoNexa-Anmeldecode', 'SICHERE ANMELDUNG', 'Dein Anmeldecode', 'Gib diesen Code zur Anmeldung ein. Er ist {minutes} Minuten gültig.', 'Wenn du diesen Code nicht angefordert hast, ignoriere diese E-Mail.'], 'bug_report'=>['[MeteoNexa Bug] {report_id} · {title}', 'METEONEXA SUPPORT', 'Neue Fehlermeldung', 'Eine neue Meldung wurde aus dem Bereich {section} gesendet.', 'Die Meldung und mögliche Anhänge wurden direkt vom MeteoNexa-Server versendet.'],],];
}
function meteonexa_seed_email_templates(PDO $pdo) : void {
    static $done =[];
    $key = spl_object_id($pdo);
    if (isset($done[$key]))return;
    $done[$key] = true;
    if (!function_exists('meteonexa_db_table_exists')||!meteonexa_db_table_exists($pdo, 'email_templates'))return;
    $insert = $pdo->prepare("INSERT OR IGNORE INTO email_templates(template_key,locale,subject_template,kicker,heading,intro,footer,updated_at)
        VALUES(:template,:locale,:subject,:kicker,:heading,:intro,:footer,:updated)");
    $now = gmdate('c');
    foreach (meteonexa_email_template_defaults() as $locale=>$templates) {
        foreach ($templates as $templateKey=>$row) {
            $insert->execute([':template'=>$templateKey, ':locale'=>$locale, ':subject'=>$row[0], ':kicker'=>$row[1], ':heading'=>$row[2], ':intro'=>$row[3], ':footer'=>$row[4], ':updated'=>$now,]);
        }
    }
}
function meteonexa_email_replace(string $text, array $vars) : string {
    foreach ($vars as $key=>$value) {
        if (is_scalar($value)||$value===null)$text = str_replace('{' . $key . '}', (string)$value, $text);
    }
    return $text;
}
function meteonexa_email_template(PDO $pdo, string $templateKey, string $locale, array $vars =[], ? string $fallbackTemplateKey = null) : array {
    meteonexa_seed_email_templates($pdo);
    $locale = strtolower(trim($locale));
    if (!in_array($locale,['it', 'en', 'fr', 'es', 'de'], true))$locale = 'it';
    $statement = $pdo->prepare('SELECT subject_template,kicker,heading,intro,footer FROM email_templates WHERE template_key=:template AND locale=:locale LIMIT 1');
    $statement->execute([':template'=>$templateKey, ':locale'=>$locale]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        $statement->execute([':template'=>$templateKey, ':locale'=>'it']);
        $row = $statement->fetch();
    }
    if (!is_array($row)&&is_string($fallbackTemplateKey)&&trim($fallbackTemplateKey)!=='') {
        $fallbackTemplateKey = trim($fallbackTemplateKey);
        $statement->execute([':template'=>$fallbackTemplateKey, ':locale'=>$locale]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            $statement->execute([':template'=>$fallbackTemplateKey, ':locale'=>'it']);
            $row = $statement->fetch();
        }
    }
    if (!is_array($row)) {
        $defaults = meteonexa_email_template_defaults();
        $defaultKey = is_string($fallbackTemplateKey)&&trim($fallbackTemplateKey)!=='' ? trim($fallbackTemplateKey) : $templateKey;
        $fallback = $defaults[$locale][$defaultKey]??$defaults['it'][$defaultKey]??$defaults[$locale][$templateKey]??$defaults['it'][$templateKey]??['', '', '', '', ''];
        $row =['subject_template'=>$fallback[0], 'kicker'=>$fallback[1], 'heading'=>$fallback[2], 'intro'=>$fallback[3], 'footer'=>$fallback[4]];
    }
    foreach (['subject_template', 'kicker', 'heading', 'intro', 'footer'] as $field) {
        $row[$field] = meteonexa_email_replace((string)($row[$field]??''), $vars);
    }
    $row['subject_template'] = trim((string)preg_replace('/[\r\n]+/', ' ', (string)$row['subject_template']));
    return $row;
}
function meteonexa_render_branded_email(array $template, string $appName, string $language, string $contentHtml, string $contentPlain) : array {
    $e = static fn(string $value) : string=>htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $language = in_array(strtolower($language),['it', 'en', 'fr', 'es', 'de'], true) ? strtolower($language) : 'it';
    $appName = trim($appName)!=='' ? trim($appName) : 'MeteoNexa';
    $subject = trim((string)($template['subject_template']??$appName));
    $kicker = $e((string)($template['kicker']??''));
    $heading = $e((string)($template['heading']??''));
    $intro = nl2br($e((string)($template['intro']??'')), false);
    $footer = nl2br($e((string)($template['footer']??'')), false);
    $escapedAppName = $e($appName);
    $html = '<!doctype html><html lang="' . $e($language) . '"><body style="margin:0;padding:0;background:#f3f7fb;font-family:Arial,Helvetica,sans-serif;color:#15314a">' . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0;background:#f3f7fb;border-collapse:collapse"><tr><td align="center" style="padding:32px 14px">' . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:660px;margin:0 auto;border-collapse:separate;border-spacing:0;background:#ffffff;border:1px solid #d9e6f1;border-radius:22px;overflow:hidden">' . '<tr><td align="center" style="padding:28px 34px 24px;background:#0d3557"><img src="cid:meteonexa-logo" width="64" height="64" alt="' . $escapedAppName . '" style="display:block;width:64px;height:64px;margin:0 auto 11px;border:0"><div style="color:#ffffff;font-size:24px;line-height:1.2;font-weight:800">' . $escapedAppName . '</div><div style="margin-top:8px;color:#77d5ff;font-size:11px;font-weight:800;letter-spacing:.12em">' . $kicker . '</div></td></tr>' . '<tr><td style="padding:29px 34px 8px"><h1 style="margin:0 0 10px;color:#102d47;font-size:23px;line-height:1.3;font-weight:800">' . $heading . '</h1><div style="color:#58718a;font-size:14px;line-height:1.65">' . $intro . '</div></td></tr>' . '<tr><td style="padding:15px 34px 24px">' . $contentHtml . '</td></tr>' . '<tr><td style="padding:0 34px 30px"><div style="padding-top:16px;border-top:1px solid #e5edf4;color:#72879a;font-size:12px;line-height:1.6">' . $footer . '</div></td></tr>' . '</table><div style="padding:14px 12px 0;color:#8295a7;font-size:11px">© ' . date('Y') . ' ' . $escapedAppName . '</div>' . '</td></tr></table></body></html>';
    $plain = trim((string)($template['heading']??'')) . "\n" . trim((string)($template['intro']??'')) . "\n\n" . trim($contentPlain) . "\n\n" . trim((string)($template['footer']??''));
    return['subject'=>$subject, 'html'=>$html, 'plain'=>$plain];
}
