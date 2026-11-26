<?php

it('reports healthy', function () {
    $this->get('/up')->assertOk();
});
