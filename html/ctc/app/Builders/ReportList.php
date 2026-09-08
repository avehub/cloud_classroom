<?php


namespace App\Builders;

class ReportList extends Builder
{

    public function handleUsers(array $reports)
    {
        $users = $this->getUsers($reports);

        foreach ($reports as $key => $report) {
            $reports[$key]['owner'] = $users[$report['owner_id']] ?? null;
        }

        return $reports;
    }

    public function getUsers(array $reports)
    {
        $ids = kg_array_column($reports, 'owner_id');

        return $this->getShallowUserByIds($ids);
    }

}
