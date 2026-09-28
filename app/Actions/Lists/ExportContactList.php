<?php

namespace App\Actions\Lists;

use App\Models\Contact;
use App\Models\ContactList;

/**
 * Writes a list's contacts out as CSV. The header uses the same names the
 * importer recognises, so an exported file maps itself when imported again.
 */
class ExportContactList
{
    /**
     * How many contacts to hold in memory at a time.
     */
    private const CHUNK = 1000;

    /**
     * Leading characters that make a spreadsheet read a cell as a formula.
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @var array<string, string>
     */
    public const COLUMNS = [
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'country' => 'Country',
    ];

    /**
     * Write the list's contacts to the given stream.
     *
     * @param  resource  $stream
     */
    public function handle(ContactList $list, $stream): void
    {
        fputcsv($stream, array_values(self::COLUMNS), escape: '');

        $list->contacts()
            ->lazyById(self::CHUNK)
            ->each(fn (Contact $contact) => fputcsv($stream, $this->row($contact), escape: ''));
    }

    /**
     * The download's file name, taken from the list name.
     */
    public function fileName(ContactList $list): string
    {
        $name = str($list->name)->slug()->value();

        return ($name !== '' ? $name : 'list-'.$list->id).'.csv';
    }

    /**
     * The contact's cells, in the order of COLUMNS.
     *
     * @return array<int, string>
     */
    public function row(Contact $contact): array
    {
        return collect(self::COLUMNS)
            ->keys()
            ->map(fn (string $column): string => $this->cell($column, $contact))
            ->all();
    }

    private function cell(string $column, Contact $contact): string
    {
        $value = (string) $contact->{$column};

        // The importer stores a greeting placeholder for nameless rows; it is
        // not a real name and should not travel to another tool as one.
        if ($column === 'first_name' && $value === Contact::DEFAULT_FIRST_NAME) {
            return '';
        }

        return $this->neutraliseFormula($column, $value);
    }

    /**
     * Prefix a value a spreadsheet would run as a formula, so an imported lead
     * cannot plant one in whoever opens the export. A phone number such as
     * "+44 20 7946 0958" is left alone: it is data, and the prefix would mangle it.
     */
    public function neutraliseFormula(string $column, string $value): string
    {
        if ($value === '' || ! in_array($value[0], self::FORMULA_TRIGGERS, true)) {
            return $value;
        }

        if ($column === 'phone' && preg_match('/^\+?[\d\s().-]+$/', $value)) {
            return $value;
        }

        return "'".$value;
    }
}
