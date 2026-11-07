<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Localization;

/**
 * Tiny, dependency-free translation catalog for the plugin's chat replies.
 *
 * Keys resolve against the active language, falling back to English when a
 * language or key is missing. Placeholders use sprintf (`%s`, `%d`); literal
 * percent signs in a template must be written as `%%`.
 *
 * Currently ships English and German; adding a language is just another array
 * under {@see CATALOG} (Spanish/Turkish fall back to English until translated).
 */
final class Translator
{
    public const SUPPORTED = ['en', 'de'];

    /** @var array<string, array<string, string>> */
    private const CATALOG = [
        'en' => [
            'router.nothing' => "There's nothing waiting for confirmation. Type `/fastbill help` to see what I can do.",
            'error.rate_limited' => 'FastBill is rate-limiting requests right now. Please wait a bit and try again.',
            'error.auth' => 'FastBill rejected the credentials. Please check the account e-mail and API key in the plugin settings.',
            'error.generic' => 'FastBill returned an error: %s',

            'help.unknown_prefix' => "I didn't get that. Here's what I can do:",
            'help.overview' => "**Synafastbill — talk to your FastBill account**\n\n- `/fastbill create invoice for <customer>` — draft a new invoice (guided)\n- `/fastbill register payment <paste bank text>` — match & register a payment\n- `/fastbill show overdue` — list overdue invoices\n- `/fastbill help` — this overview\n\nAll write actions ask for confirmation first. Invoices are created as **drafts** —\nthey are never completed, sent, or deleted.",

            'overdue.none' => 'No overdue invoices — everything is paid or within its due date.',
            'overdue.title' => '**Overdue invoices**',
            'overdue.col.invoice' => 'Invoice',
            'overdue.col.due' => 'Due date',
            'overdue.col.dayslate' => 'Days late',
            'overdue.col.amount' => 'Amount',
            'overdue.summary' => '**%d overdue · %s outstanding**',

            'create.cancelled' => 'Okay, cancelled. No invoice was created.',
            'create.none_in_progress' => 'There is no draft in progress. Start with `/fastbill create invoice for <customer>`.',
            'create.pick_number' => 'Please reply `/fastbill` and the number of the customer you mean, or `/fastbill cancel`.',
            'create.which_customer' => 'Which customer is the invoice for? For example: `/fastbill create invoice for ACME GmbH`.',
            'create.not_found' => 'I could not find a customer matching "%s". Try a different spelling.',
            'create.found_many' => 'I found %d customers matching "%s". Which one?',
            'create.reply_number' => 'Reply `/fastbill` and a number, or `/fastbill cancel`.',
            'create.reply_between' => 'Please reply `/fastbill` and a number between 1 and %d, or `/fastbill cancel`.',
            'create.no_previous' => 'Found **%s** (customer #%d%s), but there is no previous invoice to copy. Open the Synafastbill page to add line items, then I can create the draft.',
            'create.found_customer' => 'Found **%s** (customer #%d%s).',
            'create.similar_question' => 'Their last invoice %s (%s) looks like this — shall I create a **similar draft**?',
            'create.col.desc' => 'Description',
            'create.col.qty' => 'Qty',
            'create.col.unitprice' => 'Unit price',
            'create.col.vat' => 'VAT',
            'create.net_total' => 'Net total ≈ **%s**. Reply `/fastbill yes` to create the draft, or `/fastbill no` to cancel.',
            'create.say_yes' => 'Reply `/fastbill yes` to create the draft, or `/fastbill no` to cancel. A number does not confirm.',
            'create.incomplete' => 'The draft was incomplete, so nothing was created. Please start again.',
            'create.success' => 'Draft invoice **#%d** created for %s with %d line item(s). It stays a draft until you finalise it in FastBill.',

            'pay.cancelled' => 'Okay, cancelled. No payment was registered.',
            'pay.none_in_progress' => 'There is no payment in progress. Paste the bank text after `/fastbill register payment`.',
            'pay.pick_number' => 'Please reply `/fastbill` and the number of the invoice this payment belongs to, or `/fastbill cancel`.',
            'pay.no_amount' => 'I could not read a payment amount from that text. Please paste the bank line including the amount.',
            'pay.not_incoming' => 'That amount is zero or money going out. I only register payments that come in.',
            'pay.say_yes' => 'Reply `/fastbill yes` to register the payment, or `/fastbill no` to cancel. A number does not confirm.',
            'pay.no_match' => 'I read a payment of %s%s but found no matching open invoice. Please check the amount or invoice number.',
            'pay.for_invoice' => ' for %s',
            'pay.confirm' => 'Register %s as paid on %s for invoice **%s** (open %s)? Reply `/fastbill yes` or `/fastbill no`.',
            'pay.confirm_short' => 'Register %s as paid on %s for invoice **%s**? Reply `/fastbill yes` or `/fastbill no`.',
            'pay.several' => 'Several open invoices could match this %s payment. Which one?',
            'pay.reply_number' => 'Reply `/fastbill` and a number, or `/fastbill cancel`.',
            'pay.reply_between' => 'Please reply `/fastbill` and a number between 1 and %d, or `/fastbill cancel`.',
            'pay.incomplete' => 'The payment was incomplete, so nothing was registered. Please start again.',
            'pay.success' => 'Registered payment on invoice **%s** as paid on %s.',
        ],
        'de' => [
            'router.nothing' => 'Es wartet nichts auf eine Bestätigung. Gib `/fastbill hilfe` ein, um zu sehen, was ich kann.',
            'error.rate_limited' => 'FastBill drosselt gerade die Anfragen. Bitte warte einen Moment und versuche es erneut.',
            'error.auth' => 'FastBill hat die Zugangsdaten abgelehnt. Bitte prüfe die Konto-E-Mail und den API-Schlüssel in den Plugin-Einstellungen.',
            'error.generic' => 'FastBill hat einen Fehler zurückgegeben: %s',

            'help.unknown_prefix' => 'Das habe ich nicht verstanden. Hier ist, was ich kann:',
            'help.overview' => "**Synafastbill — sprich mit deinem FastBill-Konto**\n\n- `/fastbill erstelle Rechnung für <Kunde>` — neue Rechnung (geführt) anlegen\n- `/fastbill Zahlung erfassen <Banktext einfügen>` — Zahlung zuordnen & buchen\n- `/fastbill zeige überfällige` — überfällige Rechnungen auflisten\n- `/fastbill hilfe` — diese Übersicht\n\nAlle schreibenden Aktionen werden vorher bestätigt. Rechnungen werden als **Entwurf**\nangelegt — sie werden nie finalisiert, versendet oder gelöscht.",

            'overdue.none' => 'Keine überfälligen Rechnungen — alles ist bezahlt oder noch nicht fällig.',
            'overdue.title' => '**Überfällige Rechnungen**',
            'overdue.col.invoice' => 'Rechnung',
            'overdue.col.due' => 'Fällig am',
            'overdue.col.dayslate' => 'Tage überfällig',
            'overdue.col.amount' => 'Betrag',
            'overdue.summary' => '**%d überfällig · %s offen**',

            'create.cancelled' => 'Okay, abgebrochen. Es wurde keine Rechnung erstellt.',
            'create.none_in_progress' => 'Es ist kein Entwurf in Bearbeitung. Beginne mit `/fastbill erstelle Rechnung für <Kunde>`.',
            'create.pick_number' => 'Bitte antworte mit `/fastbill` und der Nummer des gewünschten Kunden oder `/fastbill abbrechen`.',
            'create.which_customer' => 'Für welchen Kunden ist die Rechnung? Zum Beispiel: `/fastbill erstelle Rechnung für ACME GmbH`.',
            'create.not_found' => 'Ich konnte keinen Kunden finden, der zu "%s" passt. Versuche eine andere Schreibweise.',
            'create.found_many' => 'Ich habe %d Kunden gefunden, die zu "%s" passen. Welchen?',
            'create.reply_number' => 'Antworte mit `/fastbill` und einer Nummer oder `/fastbill abbrechen`.',
            'create.reply_between' => 'Bitte antworte mit `/fastbill` und einer Nummer zwischen 1 und %d oder `/fastbill abbrechen`.',
            'create.no_previous' => 'Gefunden: **%s** (Kunde #%d%s), aber es gibt keine frühere Rechnung zum Kopieren. Öffne die Synafastbill-Seite, um Positionen hinzuzufügen, dann kann ich den Entwurf erstellen.',
            'create.found_customer' => 'Gefunden: **%s** (Kunde #%d%s).',
            'create.similar_question' => 'Ihre letzte Rechnung %s (%s) sieht so aus — soll ich einen **ähnlichen Entwurf** erstellen?',
            'create.col.desc' => 'Beschreibung',
            'create.col.qty' => 'Menge',
            'create.col.unitprice' => 'Einzelpreis',
            'create.col.vat' => 'MwSt',
            'create.net_total' => 'Nettosumme ≈ **%s**. Antworte `/fastbill ja`, um den Entwurf zu erstellen, oder `/fastbill nein`, um abzubrechen.',
            'create.say_yes' => 'Antworte `/fastbill ja`, um den Entwurf zu erstellen, oder `/fastbill nein`, um abzubrechen. Eine Nummer bestätigt nicht.',
            'create.incomplete' => 'Der Entwurf war unvollständig, es wurde nichts erstellt. Bitte beginne erneut.',
            'create.success' => 'Rechnungsentwurf **#%d** für %s mit %d Position(en) erstellt. Er bleibt ein Entwurf, bis du ihn in FastBill finalisierst.',

            'pay.cancelled' => 'Okay, abgebrochen. Es wurde keine Zahlung gebucht.',
            'pay.none_in_progress' => 'Es ist keine Zahlung in Bearbeitung. Füge den Banktext nach `/fastbill Zahlung erfassen` ein.',
            'pay.pick_number' => 'Bitte antworte mit `/fastbill` und der Nummer der Rechnung, zu der diese Zahlung gehört, oder `/fastbill abbrechen`.',
            'pay.no_amount' => 'Ich konnte in diesem Text keinen Zahlungsbetrag erkennen. Bitte füge die Bankzeile inklusive Betrag ein.',
            'pay.not_incoming' => 'Dieser Betrag ist null oder Geld, das abgeht. Ich buche nur Zahlungseingänge.',
            'pay.say_yes' => 'Antworte `/fastbill ja`, um die Zahlung zu buchen, oder `/fastbill nein`, um abzubrechen. Eine Nummer bestätigt nicht.',
            'pay.no_match' => 'Ich habe eine Zahlung über %s%s gelesen, aber keine passende offene Rechnung gefunden. Bitte prüfe den Betrag oder die Rechnungsnummer.',
            'pay.for_invoice' => ' für %s',
            'pay.confirm' => 'Zahlung über %s als bezahlt am %s für Rechnung **%s** (offen %s) buchen? Antworte `/fastbill ja` oder `/fastbill nein`.',
            'pay.confirm_short' => 'Zahlung über %s als bezahlt am %s für Rechnung **%s** buchen? Antworte `/fastbill ja` oder `/fastbill nein`.',
            'pay.several' => 'Mehrere offene Rechnungen könnten zu dieser Zahlung über %s passen. Welche?',
            'pay.reply_number' => 'Antworte mit `/fastbill` und einer Nummer oder `/fastbill abbrechen`.',
            'pay.reply_between' => 'Bitte antworte mit `/fastbill` und einer Nummer zwischen 1 und %d oder `/fastbill abbrechen`.',
            'pay.incomplete' => 'Die Zahlung war unvollständig, es wurde nichts gebucht. Bitte beginne erneut.',
            'pay.success' => 'Zahlung für Rechnung **%s** als bezahlt am %s gebucht.',
        ],
    ];

    private readonly string $language;

    public function __construct(string $language)
    {
        $this->language = isset(self::CATALOG[$language]) ? $language : 'en';
    }

    public function language(): string
    {
        return $this->language;
    }

    public function trans(string $key, string|int|float ...$args): string
    {
        $template = self::CATALOG[$this->language][$key] ?? self::CATALOG['en'][$key] ?? $key;

        return [] === $args ? $template : vsprintf($template, $args);
    }
}
