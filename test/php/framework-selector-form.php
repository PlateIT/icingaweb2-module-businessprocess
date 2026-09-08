<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Run after bootstrapping Icinga Web and loading the module's AddNodeForm.

use Icinga\Module\Businessprocess\Forms\AddNodeForm;

$form = new class extends AddNodeForm {
    protected function assemble(): void
    {
        $this->assembleKubernetesSelectorElements();
    }
};
$form->ensureAssembled();
$validators = $form->getElement('selector_id')->getValidators();
foreach (['payments', 'app.prod-01_test'] as $valid) {
    if (! $validators->isValid($valid)) {
        throw new RuntimeException('Valid selector ID rejected');
    }
}
foreach (['space name', '../private', "trailing\n", '<script>'] as $invalid) {
    if ($validators->isValid($invalid)) {
        throw new RuntimeException('Invalid selector ID accepted');
    }
}
echo "IPL dynamic selector form and validation OK\n";
