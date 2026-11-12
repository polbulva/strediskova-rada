<?php

const NOTES_MARKER = '<!--html-->';

/** Povolené typy bodů osnovy ('' = běžný text). */
const ITEM_TYPES = [
    '' => 'Text',
    'trips' => 'Výpravy',
    'timetable' => 'Rozpis schůzek',
    'past' => 'Proběhlé akce',
    'upcoming' => 'Chystané akce',
];

function itemTypeOrEmpty(mixed $type): string
{
    return is_string($type) && isset(ITEM_TYPES[$type]) ? $type : '';
}

/** Odhad typu podle názvu pro výchozí program bez uložené osnovy. */
function guessItemType(string $title): string
{
    $lower = mb_strtolower(trim($title));
    return match (true) {
        $lower === 'výpravy' => 'trips',
        $lower === 'vedoucí družin a družiny' => 'timetable',
        str_contains($lower, 'proběhlé akce') => 'past',
        str_contains($lower, 'chystané akce') => 'upcoming',
        default => '',
    };
}

function unwrapNode(DOMNode $node): void
{
    while ($node->firstChild) {
        $node->parentNode->insertBefore($node->firstChild, $node);
    }
    $node->parentNode->removeChild($node);
}

function cleanRichNode(DOMNode $parent): void
{
    $allowed = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'img'];
    $dropped = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'video', 'audio'];

    foreach (iterator_to_array($parent->childNodes) as $node) {
        if ($node instanceof DOMText) {
            continue;
        }
        if (!($node instanceof DOMElement)) {
            $parent->removeChild($node);
            continue;
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, $dropped, true)) {
            $parent->removeChild($node);
            continue;
        }

        cleanRichNode($node);

        if ($tag === 'div') {
            $paragraph = $node->ownerDocument->createElement('p');
            while ($node->firstChild) {
                $paragraph->appendChild($node->firstChild);
            }
            $parent->replaceChild($paragraph, $node);
            continue;
        }
        if (!in_array($tag, $allowed, true)) {
            unwrapNode($node);
            continue;
        }

        $keep = [];
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (!preg_match('~^(https?://|mailto:)~i', $href)) {
                unwrapNode($node);
                continue;
            }
            $keep = ['href' => $href, 'target' => '_blank', 'rel' => 'noopener noreferrer'];
        } elseif ($tag === 'img') {
            $src = trim($node->getAttribute('src'));
            if (!preg_match('~^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$~', $src)) {
                $parent->removeChild($node);
                continue;
            }
            $keep = ['src' => $src, 'alt' => ''];
        } elseif ($tag === 'td' || $tag === 'th') {
            foreach (['colspan', 'rowspan'] as $span) {
                if (ctype_digit($node->getAttribute($span)) && (int) $node->getAttribute($span) > 1 && (int) $node->getAttribute($span) <= 20) {
                    $keep[$span] = $node->getAttribute($span);
                }
            }
        }

        foreach (iterator_to_array($node->attributes) as $attribute) {
            $node->removeAttributeNode($attribute);
        }
        foreach ($keep as $name => $value) {
            $node->setAttribute($name, $value);
        }
    }
}

/** HTML z editoru poznámek zbaví všeho kromě bezpečných značek; prostý text vrací beze změny. */
function sanitizeNotesHtml(string $raw): string
{
    $raw = trim(str_replace("\r\n", "\n", $raw));
    if (!str_starts_with($raw, NOTES_MARKER)) {
        return $raw;
    }
    $html = substr($raw, strlen(NOTES_MARKER));

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="root">' . $html . '</div></body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $document->getElementById('root');
    if (!$root) {
        return '';
    }
    cleanRichNode($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $document->saveHTML($child);
    }
    $out = trim($out);
    if (trim(strip_tags($out, '<img><table>')) === '' && !str_contains($out, '<img') && !str_contains($out, '<table')) {
        return '';
    }
    return NOTES_MARKER . $out;
}

function isRichNotes(?string $notes): bool
{
    return str_starts_with(trim((string) $notes), NOTES_MARKER);
}

/** Obsah pro zobrazení i pro editor (u prostého textu odrážky podle odsazení). */
function notesInnerHtml(?string $notes): string
{
    $notes = trim((string) $notes);
    return isRichNotes($notes) ? substr($notes, strlen(NOTES_MARKER)) : renderPlainNotes($notes);
}

function renderNotes(?string $notes): string
{
    $inner = notesInnerHtml($notes);
    return $inner !== '' && isRichNotes($notes) ? '<div class="rich">' . $inner . '</div>' : $inner;
}

function renderRichEditor(?string $notes, string $field = 'notes', string $name = ''): string
{
    $buttons = [
        ['bold', 'format_bold', 'Tučně'], ['italic', 'format_italic', 'Kurzíva'], ['underline', 'format_underlined', 'Podtržení'],
        ['ul', 'format_list_bulleted', 'Odrážky'], ['ol', 'format_list_numbered', 'Číslovaný seznam'], ['outdent', 'format_indent_decrease', 'Předsádit'], ['indent', 'format_indent_increase', 'Odsadit (podbod)'],
        ['link', 'link', 'Odkaz'], ['table', 'table_chart', 'Vložit tabulku'], ['image', 'image', 'Vložit obrázek'],
        ['row+', '+ř', 'Přidat řádek tabulky'], ['col+', '+s', 'Přidat sloupec tabulky'],
        ['row-', '−ř', 'Smazat řádek tabulky'], ['col-', '−s', 'Smazat sloupec tabulky'],
        ['clear', 'format_clear', 'Vymazat formátování'],
    ];
    $bar = '';
    foreach ($buttons as [$command, $label, $title]) {
        $bar .= '<button type="button" data-rte-cmd="' . $command . '" title="' . h($title) . '">' . (preg_match('~^[a-z_]+$~', $label) ? icon($label) : h($label)) . '</button>';
    }

    return '<div class="rte" data-rte><div class="rte-bar">' . $bar . '</div>'
        . '<div class="rte-area" contenteditable="true" data-placeholder="Poznámky – lze použít odrážky, tabulky i obrázky">' . notesInnerHtml($notes) . '</div>'
        . '<input type="hidden" data-field="' . h($field) . '"' . ($name !== '' ? ' name="' . h($name) . '"' : '') . ' value="' . h((string) $notes) . '">'
        . '<input type="file" accept="image/*" hidden data-rte-file></div>';
}
