<?php

header('Content-Type: application/json; charset=UTF-8');

/* ════════════════════════════════════════
   INPUT — supports both:
   - GET  ?email=xyz@gmail.com&pass=xxxx&limit=5   (manual testing / verify_status.php style)
   - POST body {email, pass, limit}                (fampayController.js — sends via POST to
                                                      keep the app password out of server logs
                                                      and any URL-based caching/proxy layers)
   $_GET only ever reads query-string params, never a POST body, so POST
   callers were always getting empty email/pass here even though the
   body had real values — that's what "email and pass params are
   required" meant despite the form having correct values.
════════════════════════════════════════ */

$jsonBody = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $jsonBody = $decoded;
}

$email = trim($_GET['email'] ?? $jsonBody['email'] ?? $_POST['email'] ?? '');
$pass  = trim($_GET['pass']  ?? $jsonBody['pass']  ?? $_POST['pass']  ?? '');
$limit = (int) ($_GET['limit'] ?? $jsonBody['limit'] ?? $_POST['limit'] ?? 20);

if ($limit <= 0)  $limit = 20;
if ($limit > 100) $limit = 100; // safety cap

if ($email === '' || $pass === '') {
    echo json_encode([
        'status' => false,
        'error'  => 'email and pass params are required.',
        'data'   => []
    ], JSON_PRETTY_PRINT);
    exit;
}

/* ════════════════════════════════════════
   CONNECT
════════════════════════════════════════ */

// Kai server pe pehla flag kaam nahi karta, isliye fallback flags try karenge
$mailboxOptions = [
    "{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX",
    "{imap.gmail.com:993/imap/ssl/novalidate-cert/norsh}INBOX",
    "{imap.gmail.com:993/imap/ssl}INBOX",
    "{imap.gmail.com:143/imap/tls/novalidate-cert}INBOX",
];

$imap    = false;
$errMsg  = '';
$mailbox = '';

foreach ($mailboxOptions as $opt) {
    imap_errors();
    imap_alerts();

    set_error_handler(function () { return true; }, E_WARNING | E_NOTICE);
    $imap = @imap_open($opt, $email, $pass, 0, 1);
    restore_error_handler();

    $mailbox = $opt;

    if ($imap) {
        break; // connect ho gaya, aage badhna band
    }

    $errors  = imap_errors() ?: [];
    $alerts  = imap_alerts() ?: [];
    $all     = array_merge($errors, $alerts);
    $thisErr = implode(' ', $all) ?: (imap_last_error() ?: '');
    $errMsg  = $thisErr ?: $errMsg;

    // Agar password hi galat hai to further flags try karne ka faayda nahi
    if (stripos($thisErr, 'AUTHENTICATIONFAILED') !== false ||
        stripos($thisErr, 'Too many login failures') !== false) {
        break;
    }
}

if (!$imap) {

    if (stripos($errMsg, 'Too many login failures') !== false) {
        $friendly = 'Invalid App Password. Please check your App Password and try again.';
    } elseif (stripos($errMsg, 'AUTHENTICATIONFAILED') !== false) {
        $friendly = 'Invalid App Password. Please generate a new App Password and try again.';
    } elseif (stripos($errMsg, 'Connection refused') !== false) {
        $friendly = 'Connection refused. Please check your internet or firewall settings.';
    } elseif (stripos($errMsg, 'certificate') !== false) {
        $friendly = 'SSL certificate error. Please check your server settings.';
    } else {
        $friendly = $errMsg ?: 'Connection failed. Please check your credentials.';
    }

    echo json_encode([
        'status'    => false,
        'error'     => $friendly,
        'raw_error' => $errMsg,   // debug ke liye — asli wajah yahan dikhegi
        'data'      => []
    ], JSON_PRETTY_PRINT);
    exit;
}

/* ════════════════════════════════════════
   SEARCH — sirf "FamX account" wale mails
   (koi date filter nahin, jitna bola limit utna)
════════════════════════════════════════ */

$criteria = 'SUBJECT "FamX account"';
$search   = @imap_search($imap, $criteria);

if (!$search) {
    imap_close($imap);
    echo json_encode([
        'status' => true,
        'error'  => null,
        'data'   => []
    ], JSON_PRETTY_PRINT);
    exit;
}

// Latest mails pehle, fir $limit tak trim
rsort($search);
$search = array_slice($search, 0, $limit);

$results = [];

/* ════════════════════════════════════════
   LOOP — har mail ke liye ek hi baar me
   subject + body + parsed full detail
════════════════════════════════════════ */

foreach ($search as $msgno) {

    /* ---- overview (subject/from/date) ---- */
    $overview = imap_fetch_overview($imap, (string) $msgno, 0);
    $ov       = $overview[0] ?? null;

    // subject decode
    $subject = '(No Subject)';
    if ($ov && !empty($ov->subject)) {
        $parts   = imap_mime_header_decode($ov->subject);
        $decoded = '';
        foreach ($parts as $part) {
            $decoded .= ($part->charset === 'default')
                ? $part->text
                : @mb_convert_encoding($part->text, 'UTF-8', $part->charset);
        }
        $subject = trim($decoded);
    }

    // from decode
    $from = 'Unknown';
    if ($ov && !empty($ov->from)) {
        $parts   = imap_mime_header_decode($ov->from);
        $decoded = '';
        foreach ($parts as $part) {
            $decoded .= ($part->charset === 'default')
                ? $part->text
                : @mb_convert_encoding($part->text, 'UTF-8', $part->charset);
        }
        $from = trim(preg_replace('/<.*?>/', '', trim($decoded)), '"\'');
    }

    $date   = $ov && !empty($ov->date) ? date('d M Y, h:i A', strtotime($ov->date)) : 'Unknown';
    $isRead = $ov && isset($ov->seen) ? (bool) $ov->seen : false;

    // subject se amount
    $amountFromSubject = null;
    if (preg_match('/(?:₹|Rs\.?|INR)\s*([\d,]+(?:\.\d+)?)/u', $subject, $m)) {
        $amountFromSubject = (float) str_replace(',', '', $m[1]);
    }

    /* ---- full body fetch ---- */
    $structure = imap_fetchstructure($imap, $msgno);
    $rawBody   = '';

    if ($structure->type === 0) {
        $rawBody = imap_fetchbody($imap, $msgno, "1");
        if ($structure->encoding === 3)     $rawBody = base64_decode($rawBody);
        elseif ($structure->encoding === 4) $rawBody = quoted_printable_decode($rawBody);
    } elseif ($structure->type === 1 && isset($structure->parts)) {
        foreach ($structure->parts as $i => $part) {
            if ($part->subtype === 'PLAIN') {
                $rawBody = imap_fetchbody($imap, $msgno, ($i + 1));
                if ($part->encoding === 3)     $rawBody = base64_decode($rawBody);
                elseif ($part->encoding === 4) $rawBody = quoted_printable_decode($rawBody);
                break;
            }
        }
    }

    $body = trim(mb_convert_encoding(strip_tags($rawBody), 'UTF-8', 'auto'));

    /* ---- body se transaction detail parse ---- */
    $parsed = [
        'amount'   => null,
        'sender'   => null,
        'upi_id'   => null,
        'txn_id'   => null,
        'utr'      => null,
        'balance'  => null,
        'txn_time' => null,
        'purpose'  => null,
    ];

    if (preg_match('/received\s+(?:₹|Rs\.?)\s*([\d,]+(?:\.\d+)?)/ui', $body, $m))
        $parsed['amount'] = (float) str_replace(',', '', $m[1]);

    if (preg_match('/from\s+([A-Za-z][A-Za-z0-9\s]{1,40}?)\s+at\s+\d/ui', $body, $m))
        $parsed['sender'] = trim($m[1]);

    // Sender's UPI handle — common formats look like "9876543210@axl" or
    // "name@fam"/"name@ybl". Anchored near the sender's name (in
    // parentheses, right after "from X", or right after "UPI ID:")
    // rather than a bare body-wide search, so a footer/support email
    // address elsewhere in the message can't be mistaken for it.
    // Optional either way: many FamX notification emails don't include
    // it at all, in which case this stays "NA".
    if (preg_match('/from\s+[A-Za-z][A-Za-z0-9\s]{1,40}?\s*\(([a-zA-Z0-9.\-_]{2,}@[a-zA-Z]{2,15})\)/ui', $body, $m) ||
        preg_match('/UPI\s*ID[:\s]+([a-zA-Z0-9.\-_]{2,}@[a-zA-Z]{2,15})/i', $body, $m)) {
        $parsed['upi_id'] = $m[1];
    }

    if (preg_match('/transaction\s+id\s+([A-Z0-9]+)/i', $body, $m))
        $parsed['txn_id'] = $m[1];

    if (preg_match('/UTR[:\s]+(\d+)/i', $body, $m))
        $parsed['utr'] = $m[1];

    if (preg_match('/balance\s+is\s+(?:₹|Rs\.?)\s*([\d,]+(?:\.\d+)?)/ui', $body, $m))
        $parsed['balance'] = (float) str_replace(',', '', $m[1]);

    if (preg_match('/at\s+(\d{1,2}:\d{2}\s*[AP]M\s*IST,?\s*\d{1,2}\s+\w+\s+\d{4})/i', $body, $m))
        $parsed['txn_time'] = trim($m[1]);

    if (preg_match('/Purpose[:\s]+(.+?)(?:\.|$)/im', $body, $m))
        $parsed['purpose'] = trim($m[1]);

    if (empty($parsed['amount'])) {
        $parsed['amount'] = $amountFromSubject;
    }

    /* ---- datetime ko dd-mm-yyyy HH:ii:ss format me convert karo ---- */
    $finalDatetime = 'NA';
    if (!empty($parsed['txn_time'])) {
        $clean = str_ireplace(' IST', '', $parsed['txn_time']); // "04:27 PM, 06 August 2026"
        $dt    = DateTime::createFromFormat('h:i A, d F Y', trim($clean));
        if ($dt) {
            $finalDatetime = $dt->format('d-m-Y H:i:s');
        }
    }
    if ($finalDatetime === 'NA' && $date !== 'Unknown') {
        // fallback: email header date se banao
        $ts = strtotime($date);
        if ($ts) $finalDatetime = date('d-m-Y H:i:s', $ts);
    }

    /* ---- final row ----
       FamPay-to-FamPay transfers never generate a bank UTR — only
       FamX's own transaction id (e.g. FMPIB...). Falling back to
       txn_id here (instead of showing "NA") means the UTR/REF column
       always shows SOME real, usable identifier for that payment.
       ref_type tells the frontend which kind it actually got, so it
       can label the value correctly (Bank UTR vs FamX Txn ID) instead
       of showing both the same way. */
    $displayRef = $parsed['utr'] ?: ($parsed['txn_id'] ?: 'NA');
    $refType = $parsed['utr'] ? 'utr' : ($parsed['txn_id'] ? 'txn' : 'none');
    $results[] = [
        'name'     => $parsed['sender'] ?: 'NA',
        'upi_id'   => $parsed['upi_id'] ?: 'NA',
        'utr'      => $displayRef,
        'ref_type' => $refType,
        'datetime' => $finalDatetime,
        'amount'   => $parsed['amount'],
        'purpose'  => $parsed['purpose'] ?: 'NA',
        'txn_id'   => $parsed['txn_id'] ?: 'NA',
        'balance'  => $parsed['balance'],
    ];
}

imap_close($imap);

echo json_encode([
    'status' => true,
    'error'  => null,
    'data'   => $results
], JSON_PRETTY_PRINT);