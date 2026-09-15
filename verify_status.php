<?php
// verify_status.php
//
// Rewritten AGAIN — this time using the exact same subject filter and
// body-parsing regex as history.php, which is confirmed working against
// real FamX emails in this inbox. The previous version guessed at a
// generic "INR/Rs/₹/Amount <number>" pattern, but the real emails say
// "received ₹<amount>" — a different phrase — so that guess never
// matched anything, regardless of the order-id vs amount matching logic
// around it. This version:
//   - narrows the IMAP search to SUBJECT "FamX account" (same as
//     history.php), instead of scanning all recent mail
//   - extracts amount via the same "received ₹X" pattern
//   - extracts utr via the same "UTR: 123..." pattern
//   - extracts txn_id via the same "transaction id XXXX" pattern
//   - matches on AMOUNT for the auto-poll case (checkout.html knows its
//     own expected amount), since the order_id/txn tag the app shows
//     (e.g. "ZPPZP...") is FamX's own reference note, not something
//     that reliably appears standalone in the email body
//   - matches on the user-typed UTR/txn_id directly for the manual-
//     verify case (mode=utr)

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$order_id_param  = $_GET['utr'] ?? '';   // kept as "utr" for backward compat with checkout.html's query string
$email            = $_GET['email'] ?? '';
$pass             = $_GET['pass'] ?? '';
$expected_amount  = isset($_GET['amount']) ? (float)$_GET['amount'] : null;
$mode             = $_GET['mode'] ?? 'order';   // 'order' = auto-poll (match by amount), 'utr' = manual entry (match by typed UTR/txn id)
$real_order_id    = $_GET['order_id'] ?? $order_id_param;
// Order-creation time (unix seconds), sent by checkout.html. Auto-poll
// matching is by AMOUNT ALONE, which is unsafe without this: any old
// email of the same amount (₹1 test payments are especially common)
// would otherwise match a brand-new order that was never actually paid.
// Emails older than this are never considered a match for mode=order.
// Not required for mode=utr, since a manually-typed real UTR is already
// a specific, genuine identifier regardless of when that email arrived.
$since_ts = isset($_GET['since']) ? (int)$_GET['since'] : null;

if (empty($order_id_param) || empty($email) || empty($pass)) {
    echo json_encode(["success" => false, "message" => "Order ID, Email and Password required."]);
    exit();
}

$pass = str_replace(' ', '', trim($pass));

$mailboxOptions = [
    "{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX",
    "{imap.gmail.com:993/imap/ssl/novalidate-cert/norsh}INBOX",
    "{imap.gmail.com:993/imap/ssl}INBOX",
    "{imap.gmail.com:143/imap/tls/novalidate-cert}INBOX",
];

$imap = false;
$errMsg = '';
foreach ($mailboxOptions as $opt) {
    imap_errors();  // clear any prior error queue before this attempt
    imap_alerts();
    set_error_handler(function () { return true; }, E_WARNING | E_NOTICE);
    $imap = @imap_open($opt, $email, $pass, 0, 1);
    restore_error_handler();
    if ($imap) break;
    $errors = imap_errors() ?: [];
    $alerts = imap_alerts() ?: [];
    $thisErr = implode(' ', array_merge($errors, $alerts)) ?: (imap_last_error() ?: '');
    if ($thisErr) $errMsg = $thisErr;
}

if (!$imap) {
    echo json_encode(["success" => false, "message" => "IMAP Auth Failed", "raw_error" => $errMsg]);
    exit();
}

// Same narrow search as history.php — only FamX payment notification mails
$criteria = 'SUBJECT "FamX account"';
$search = @imap_search($imap, $criteria);

$found_transaction = null;

// Simple duplicate guard: prevents the same email confirming two
// different orders of the same amount.
$usedFile = __DIR__ . '/.used_utrs.json';
$used = [];
if (file_exists($usedFile)) {
    $used = json_decode(file_get_contents($usedFile), true) ?: [];
}

if ($search) {
    rsort($search);
    $search = array_slice($search, 0, 30); // recent mail only

    foreach ($search as $msgno) {
        $overview = imap_fetch_overview($imap, (string) $msgno, 0);
        $ov = $overview[0] ?? null;

        $subject = '';
        if ($ov && !empty($ov->subject)) {
            $parts = imap_mime_header_decode($ov->subject);
            $decoded = '';
            foreach ($parts as $part) {
                $decoded .= ($part->charset === 'default')
                    ? $part->text
                    : @mb_convert_encoding($part->text, 'UTF-8', $part->charset);
            }
            $subject = trim($decoded);
        }

        $emailTs = ($ov && !empty($ov->date)) ? strtotime($ov->date) : null;
        $date = $emailTs ? date('d M Y, h:i A', $emailTs) : 'Unknown';

        // Reject anything older than the order itself (mode=order only).
        // A 90s buffer absorbs clock skew between this server and the
        // mail server, and the moment between order-creation and the
        // first poll — not so wide that an old same-amount email could
        // slip in.
        if ($mode !== 'utr' && $since_ts !== null) {
            if ($emailTs === null || $emailTs < ($since_ts - 90)) continue;
        }

        $structure = imap_fetchstructure($imap, $msgno);
        $rawBody = '';
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

        // Amount — same pattern as history.php ("received ₹X")
        $amount = null;
        if (preg_match('/received\s+(?:₹|Rs\.?)\s*([\d,]+(?:\.\d+)?)/ui', $body, $m)) {
            $amount = (float) str_replace(',', '', $m[1]);
        }
        if ($amount === null) continue;

        // UTR / txn id — same patterns as history.php
        $utr = null;
        if (preg_match('/UTR[:\s]+(\d+)/i', $body, $m)) {
            $utr = $m[1];
        }
        $txn_id = null;
        if (preg_match('/transaction\s+id\s+([A-Z0-9]+)/i', $body, $m)) {
            $txn_id = $m[1];
        }
        $identifier = $utr ?: $txn_id;
        if (!$identifier) continue;

        if ($mode === 'utr') {
            // Manual entry: match the user-typed UTR/txn id directly
            // against whatever we extracted (or fall back to raw body
            // search, since the user might type either the UTR or the
            // txn id and we only extracted one of them above).
            $typed = $order_id_param;
            $matches = ($utr === $typed) || ($txn_id === $typed) || (strpos($body, $typed) !== false);
            if (!$matches) continue;
        } elseif ($expected_amount !== null && abs($amount - $expected_amount) > 0.009) {
            // Auto-poll: match by amount
            continue;
        }

        // Skip if this exact identifier was already used for a DIFFERENT order
        if (isset($used[$identifier]) && $used[$identifier] !== $real_order_id) continue;

        preg_match('/from\s+([A-Za-z][A-Za-z0-9\s]{1,40}?)\s+at\s+\d/ui', $body, $snd);
        $sender = trim($snd[1] ?? 'Unknown');

        $found_transaction = [
            "success"     => true,
            "status"      => "PAID",
            "order_id"    => $real_order_id,
            "utr"         => $identifier,
            "amount"      => $amount,
            "sender"      => $sender,
            "datetime"    => $date,
            "raw_subject" => $subject
        ];
        break;
    }
}
imap_close($imap);

if ($found_transaction) {
    $used[$found_transaction['utr']] = $real_order_id;
    @file_put_contents($usedFile, json_encode($used));
    echo json_encode($found_transaction);
} else {
    echo json_encode(["success" => false, "status" => "PENDING", "message" => "Transaction not found yet"]);
}
