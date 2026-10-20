<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Anyone who may open the trip list and seat maps may listen for changes to a trip. The event carries no data.
Broadcast::channel('passenger-trip.{tripId}', function ($user, $tripId) {
    return $user->hasPermission('trip.view');
});
