<?php

it('returns api health status', function () {
    $this->getJson('/api/health')->assertOk()->assertJsonStructure(['status', 'checks']);
});
