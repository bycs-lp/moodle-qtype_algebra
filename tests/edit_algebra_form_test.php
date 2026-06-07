<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace qtype_algebra;

use advanced_testcase;
use MoodleQuickForm;
use qtype_algebra_edit_form;
use qtype_algebra_parser;
use ReflectionClass;

/**
 * Structural tests for the algebra question editing form, focused on the
 * "Allowed functions" checkbox group.
 *
 * These tests deliberately inspect the form definition (not the rendered HTML),
 * so they stay stable across Boost / theme_mebis upgrades. They guard against
 * a regression of MBS-7923 in which inline `<br>` / `&nbsp;` separators were
 * (re)introduced inside the group, breaking the layout in both themes.
 *
 * @package    qtype_algebra
 * @copyright  2026 ISB Bayern
 * @author     Fabian Barbuia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_algebra_edit_form
 */
final class edit_algebra_form_test extends advanced_testcase {

    /**
     * Boot the editing form against a freshly created algebra question stub
     * and return the underlying MoodleQuickForm instance.
     */
    private function build_mform(): MoodleQuickForm {
        global $CFG;

        require_once($CFG->dirroot . '/question/type/algebra/edit_algebra_form.php');
        require_once($CFG->dirroot . '/question/type/algebra/questiontype.php');
        require_once($CFG->dirroot . '/question/type/algebra/parser.php');
        require_once($CFG->dirroot . '/question/engine/tests/helpers.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $syscontext = \context_system::instance();
        $generator  = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category   = $generator->create_question_category(['contextid' => $syscontext->id]);

        $question                              = new \stdClass();
        $question->category                    = $category->id;
        $question->contextid                   = $syscontext->id;
        $question->qtype                       = 'algebra';
        $question->createdby                   = get_admin()->id;
        $question->formoptions                 = new \stdClass();
        $question->formoptions->canedit        = true;
        $question->formoptions->canmove        = true;
        $question->formoptions->cansaveasnew   = false;
        $question->formoptions->repeatelements = true;

        $form = new qtype_algebra_edit_form(
            new \moodle_url('/'),
            $question,
            $category,
            new \core_question\local\bank\question_edit_contexts($syscontext)
        );

        $reflection = new ReflectionClass($form);
        $property   = $reflection->getProperty('_form');
        $property->setAccessible(true);

        return $property->getValue($form);
    }

    /**
     * The "allowedfuncs" group must expose exactly one "all" checkbox followed
     * by one checkbox per parser function, in declaration order. No other
     * elements (in particular no static HTML separators) may leak in.
     */
    public function test_allowedfuncs_group_contains_only_checkboxes_in_expected_order(): void {
        $mform = $this->build_mform();

        $this->assertTrue(
            $mform->elementExists('allowedfuncs'),
            'The "allowedfuncs" group must be defined on the editing form.'
        );

        $elements      = $mform->getElement('allowedfuncs')->getElements();
        $expectednames = array_merge(['all'], qtype_algebra_parser::$functions);
        $actualnames   = array_map(static fn($el) => $el->getName(), $elements);

        $this->assertSame(
            $expectednames,
            $actualnames,
            'allowedfuncs must expose "all" followed by every parser function, in order.'
        );

        foreach ($elements as $element) {
            $this->assertSame(
                'checkbox',
                $element->getType(),
                'allowedfuncs may only contain checkbox elements - no <br>/&nbsp; separators (MBS-7923).'
            );
        }
    }

    /**
     * "Alle Funktionen" must be pre-checked, and ticking it must disable the
     * individual function checkboxes via a properly registered disabledIf rule.
     */
    public function test_allowedfuncs_defaults_and_disabledif_are_set(): void {
        $mform = $this->build_mform();

        $this->assertSame(
            'checked',
            $mform->getElementValue('allowedfuncs[all]'),
            '"Alle Funktionen" must be pre-selected by default.'
        );

        // MoodleQuickForm stores disabledIf rules in $_dependencies, keyed by
        // the trigger element name. Shape (per HTML_QuickForm_DHTMLRulesTableless):
        //   _dependencies[triggerName][condition][triggerValue] = [dependentElement, ...]
        $reflection = new ReflectionClass($mform);
        $property   = $reflection->getProperty('_dependencies');
        $property->setAccessible(true);
        $dependencies = $property->getValue($mform);

        $this->assertArrayHasKey(
            'allowedfuncs[all]',
            $dependencies,
            'A disabledIf rule must be registered for the "Alle Funktionen" checkbox.'
        );
        $this->assertArrayHasKey(
            'checked',
            $dependencies['allowedfuncs[all]'],
            'The disabledIf rule must trigger on condition "checked".'
        );

        // Flatten the leaves under [allowedfuncs[all]][checked] and assert that
        // the dependent element list contains exactly "allowedfuncs".
        $dependents = [];
        array_walk_recursive(
            $dependencies['allowedfuncs[all]']['checked'],
            static function ($leaf) use (&$dependents): void {
                $dependents[] = $leaf;
            }
        );

        $this->assertContains(
            'allowedfuncs',
            $dependents,
            'The "allowedfuncs" group must be the element disabled by "Alle Funktionen".'
        );
    }
}
