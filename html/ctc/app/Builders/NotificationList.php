<?php


namespace App\Builders;

class NotificationList extends Builder
{

    public function handleUsers(array $notifications)
    {
        $users = $this->getUsers($notifications);

        foreach ($notifications as $key => $notification) {
            $notifications[$key]['sender'] = $users[$notification['sender_id']] ?? null;
            $notifications[$key]['receiver'] = $users[$notification['receiver_id']] ?? null;
        }

        return $notifications;
    }

    public function getUsers(array $notifications)
    {
        $senderIds = kg_array_column($notifications, 'sender_id');
        $receiverIds = kg_array_column($notifications, 'receiver_id');
        $ids = array_merge($senderIds, $receiverIds);

        return $this->getShallowUserByIds($ids);
    }

}
