<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

/**
 * @noinspection PhpInternalEntityUsedInspection
 */

namespace putyourlightson\blitzhints\services;

use Craft;
use craft\base\Component;
use craft\elements\db\ElementQuery;
use craft\elements\db\MatrixBlockQuery;
use craft\services\Deprecator;
use putyourlightson\blitzhints\models\HintModel;
use putyourlightson\blitzhints\records\HintRecord;
use ReflectionClass as ReflectionClassAlias;
use Twig\Template;

/**
 * @property int $total
 * @property HintModel[] $all
 */
class HintsService extends Component
{
    /**
     * @var HintModel[] The hints to be saved for the current request.
     */
    private array $_hints = [];

    /**
     * Gets total hints.
     */
    public function getTotal(): int
    {
        return HintRecord::find()->count();
    }

    /**
     * Gets all hints.
     *
     * @return HintModel[]
     */
    public function getAll(): array
    {
        $hints = [];

        $hintRecords = HintRecord::find()
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->all();

        foreach ($hintRecords as $record) {
            $hint = new HintModel();
            $hint->setAttributes($record->getAttributes(), false);
            $hints[] = $hint;
        }

        return $hints;
    }

    /**
     * Clears all hints.
     */
    public function clearAll(): void
    {
        HintRecord::deleteAll();
    }

    /**
     * Clears a hint.
     */
    public function clear(int $id): void
    {
        HintRecord::deleteAll([
            'id' => $id,
        ]);
    }

    /**
     * Checks for opportunities to eager-loading elements.
     */
    public function checkElementQuery(ElementQuery $elementQuery): void
    {
        if ($elementQuery instanceof MatrixBlockQuery) {
            $this->_checkMatrixRelations($elementQuery);
        }
        else {
            $this->_checkBaseRelations($elementQuery);
        }
    }

    /**
     * Saves any hints that have been prepared.
     *
     * @noinspection MissedFieldInspection
     */
    public function save(): void
    {
        $db = Craft::$app->getDb();

        foreach ($this->_hints as $hint) {
            $db->createCommand()
                ->upsert(
                    HintRecord::tableName(),
                    [
                        'fieldId' => $hint->fieldId,
                        'template' => $hint->template,
                        'line' => $hint->line,
                        'message' => $hint->message,
                        'info' => $hint->info,
                    ])
                ->execute();
        }
    }

    /**
     * Checks base relations.
     * @see \craft\fields\BaseRelationField::normalizeValue
     */
    private function _checkBaseRelations(ElementQuery $elementQuery): void
    {
        $join = $elementQuery->join[0] ?? null;

        if ($join === null) {
            return;
        }

        $relationTypes = [
            ['relations' => '{{%relations}}'],
            '{{%relations}} relations',
        ];

        if ($join[0] == 'INNER JOIN' && in_array($join[1], $relationTypes)) {
            $fieldId = $join[2][2]['relations.fieldId'] ?? null;

            if (empty($fieldId)) {
                return;
            }

            $this->_addFieldHint($fieldId);
        }
    }

    /**
     * Checks matrix relations.
     * @see \craft\elements\db\MatrixBlockQuery::beforePrepare
     */
    private function _checkMatrixRelations(MatrixBlockQuery $elementQuery): void
    {
        if (empty($elementQuery->fieldId) || empty($elementQuery->ownerId)) {
            return;
        }

        $fieldId = is_array($elementQuery->fieldId) ? $elementQuery->fieldId[0] : $elementQuery->fieldId;

        $this->_addFieldHint($fieldId);
    }

    /**
     * Adds a field hint.
     */
    private function _addFieldHint(int $fieldId): void
    {
        $field = Craft::$app->getFields()->getFieldById($fieldId);

        if ($field === null) {
            return;
        }

        $hint = $this->_getHintWithTemplateLine();

        if ($hint === null) {
            return;
        }

        $hint->fieldId = $fieldId;
        $hint->message = 'Eager-load the `' . $field->name . '` field.';
        $hint->info = 'Use the `with` parameter to eager-load sub-elements of the `' . $field->name . '` field.<br>'
            . '`{% set entries = craft.entries.with([\'' . $field->handle . '\']).all() %}`<br>'
            . '<a href="https://craftcms.com/docs/4.x/dev/eager-loading-elements.html" class="go" target="_blank">Docs</a>';

        $this->_hints[$fieldId . $hint->template . $hint->line] = $hint;
    }

    /**
     * Returns a new hint with the template and line number of the rendered template.
     */
    private function _getHintWithTemplateLine(): ?HintModel
    {
        // Get the debug backtrace
        $traces = debug_backtrace();

        // Get template class filename
        $reflector = new ReflectionClassAlias(Template::class);
        $filename = $reflector->getFileName();

        foreach ($traces as $key => $trace) {
            if (!empty($trace['file']) && $trace['file'] == $filename) {
                $templateObject = $trace['object'] ?? null;

                if ($templateObject instanceof Template) {
                    $path = $templateObject->getSourceContext()->getPath();
                    $template = str_replace(Craft::getAlias('@templates/'), '', $path);
                    $templateCodeLine = $traces[$key - 1]['line'] ?? null;
                    $line = $this->_findTemplateLine($templateObject, $templateCodeLine);

                    if ($template && $line) {
                        return new HintModel([
                            'template' => $template,
                            'line' => $line,
                        ]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Returns the template line number.
     *
     * @see Deprecator::_findTemplateLine()
     */
    private function _findTemplateLine(Template $template, int $actualCodeLine = null)
    {
        if ($actualCodeLine === null) {
            return null;
        }

        // getDebugInfo() goes upward, so the first code line that's <= the trace line will be the match
        foreach ($template->getDebugInfo() as $codeLine => $templateLine) {
            if ($codeLine <= $actualCodeLine) {
                return $templateLine;
            }
        }

        return null;
    }
}
