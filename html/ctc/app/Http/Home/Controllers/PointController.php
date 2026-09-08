<?php


namespace App\Http\Home\Controllers;

/**
 * @RoutePrefix("/point")
 */
class PointController extends Controller
{

    /**
     * @Get("/rule", name="home.point.rule")
     */
    public function ruleAction()
    {
        $point = $this->getSettings('point');

        $consumeRule = json_decode($point['consume_rule']);
        $eventRule = json_decode($point['event_rule']);

        $this->view->setVar('consume_rule', $consumeRule);
        $this->view->setVar('event_rule', $eventRule);
    }

}
