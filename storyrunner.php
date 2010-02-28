<?php

/**
 * This is a PHP implementation of the Gherkin syntax for specifying
 * features and scenarios in Cucumber, and a simple DSL for writing
 * step definitions.
 */

class StoryRunner implements SplSubject {

  const STEP_PASSED =         'step_passed';
  const STEP_FAILED =         'step_failed';
  const STEP_SKIPPED =        'step_skipped';
  const STEP_MISSING =        'step_missing';
  const STEP_PENDING =        'step_pending';
  const FEATURE_TEXT =        'feature_text';
  const SCENARIO_TEXT =       'scenario_text';
  const STORY_RUNNER_FAILED = 'story_runner_failed';
  const STORY_RUNNER_DONE =   'story_runner_done';
  private $status;
  private $error;
  private $text;
  private $observers = array();
  private $missing_step_definitions = array();
  private $results = array(
    'features'  => array('passed' => 0, 'failed' => 0),
    'scenarios' => array('passed' => 0, 'failed' => 0, 'skipped' => 0),
    'steps'     => array('passed' => 0, 'failed' => 0, 'skipped' => 0, 'missing' => 0, 'pending' => 0));

  function __construct($reporter = null) {
    if(!$reporter) $reporter = new StoryRunnerCliReporter;
    $this->attach($reporter);
    assert_options(ASSERT_ACTIVE,     1);
    assert_options(ASSERT_WARNING,    0);
    assert_options(ASSERT_QUIET_EVAL, 1);
    assert_options(ASSERT_CALLBACK,   array($this, 'assert_handler'));
  }

  function get_status() {
    return $this->status;
  }

  function get_error() {
    return (array)$this->error;
  }

  function get_text() {
    return (array)$this->text;
  }

  private function observer_key($observer) {
    return md5(serialize($observer));
  }

  function attach(SplObserver $observer) {
    $this->observers[$this->observer_key($observer)] = $observer;
  }

  function detach(SplObserver $observer) {
    unset($this->observers[$this->observer_key($observer)]);
  }

  function notify() {
    foreach($this->observers as $observer) {
      $observer->update($this);
    }
    unset($this->text);
    unset($this->error);
  }

  function run($context = array(), $feature_dir = 'features', $step_dir = 'features/steps') {
    try {
      $feature_files = glob("{$feature_dir}/*.feature");
      if(!$feature_files) {
        throw new Exception("No .feature files in {$feature_dir}");
      }
      foreach($feature_files as $feature_file) {
        $this->run_feature($this->parse_feature($feature_file), $step_dir, $context);
      }
    } catch(Exception $e) {
      $this->status = self::STORY_RUNNER_FAILED;
      $this->error = $e->getMessage();
    }
    $this->status = self::STORY_RUNNER_DONE;
    $this->notify_results();
    $this->notify_missing_steps();
  }

  private function parse_feature($feature_file) {
    $fh = fopen($feature_file, 'r');
    if(!$fh) return;
    $state = array(
      'feature' => array(),
      'in_feature' => FALSE,
      'in_scenario' => FALSE);
    while($line = fgets($fh)) if($line = trim($line)) {
      $state = $this->parse_line($line, $state);
    }
    return $state['feature'];
  }

  private function parse_line($line, $state) {
    if(preg_match('/^Feature:/i', $line)) {
      $state['feature'][] = $line;
      $state['in_feature'] = TRUE;
      $state['in_scenario'] = FALSE;
    } else if($state['in_feature'] && preg_match('/^Scenario:/i', $line)) {
      $scenario = array($line);
      $state['scenario'] =& $scenario;
      $state['feature']['scenarios'][] =& $scenario;
      $state['in_scenario'] = TRUE;
    } else if($state['in_feature'] && !$state['in_scenario']) {
      $state['feature'][] = '  ' . $line;
    } else if($state['in_feature'] && $state['in_scenario'] &&
              preg_match('/^(given|when|then|and|but)[\s]*(.*)$/i', $line, $matches)) {
      $state['scenario']['steps'][$line] = $matches[2];
    } else {
      throw new Exception("can't understand {$feature_file}: {$line}");
    }
    return $state;
  }

  private function run_feature($feature, $step_dir, $context) {
    $this->status = FEATURE_TEXT;
    $this->text = $feature;
    $this->notify();
    $scenarios_passed = 0;
    foreach($feature['scenarios'] as $scenario) {
      $this->status = SCENARIO_TEXT;
      $this->text = $scenario;
      $this->notify();
      $steps_passed = 0;
      foreach($scenario['steps'] as $step_text => $step_match) {
        $this->text = $step_text;
        if(in_array($this->status, array(self::STEP_FAILED, self::STEP_PENDING, self::STEP_SKIPPED))) {
          $this->results['steps']['skipped']++;
          $this->status = self::STEP_SKIPPED;
        } else {
          $step = new StoryStep($step_dir);
          unset($this->status);
          $step_matched = $step->match($step_match, $context);
          if($step_matched) {
            if($step_matched === 'pending') {
              $this->results['steps']['pending']++;
              $this->results['scenarios']['skipped']++;
              $this->status = self::STEP_PENDING;
            } else if($this->status != self::STEP_FAILED) {
              $this->status = self::STEP_PASSED;
              $this->results['steps']['passed']++;
              $steps_passed++;
            }
          } else {
            $this->results['steps']['missing']++;
            $this->status = self::STEP_MISSING;
            $this->define_missing_step($step_match);
          }
        }
        $this->notify();
      }
      if($steps_passed == count($scenario['steps'])) {
        $this->results['scenarios']['passed']++;
        $scenarios_passed++;
      }
    }
    if($scenarios_passed == count($feature['scenarios'])) $this->results['features']['passed']++;
  }

  private function define_missing_step($match) {
    $step = preg_replace('/"(.*)"/Ue', 'sprintf(\'"arg%d"\', ++$arg_num)', str_replace("'", "\'", $match));
    $code = array(
      "if(\$step->is('{$step}')) {",
      '  return "pending";',
      '}');
    $this->missing_step_definitions[md5(implode('', $code))] = $code;
  }

  private function notify_missing_steps() {
    if($this->status != self::STORY_RUNNER_DONE) return;
    if(!$this->missing_step_definitions) return;
    $this->text = 'Add the following to your steps definitions to implement missing steps:';
    $this->notify();
    $this->text = array_values($this->missing_step_definitions);
    $this->notify();
  }

  private function notify_results() {
    if($this->status != self::STORY_RUNNER_DONE) return;
    $this->text[] = sprintf(
      "Features:  %d ran, %d passed, %d failed.",
      $this->results['features']['passed'] + $this->results['features']['failed'],
      $this->results['features']['passed'],
      $this->results['features']['failed']);
    $this->text[] = sprintf(
      "Scenarios: %d ran, %d passed, %d failed, %d skipped.",
      $this->results['scenarios']['passed'] + $this->results['scenarios']['failed'],
      $this->results['scenarios']['passed'],
      $this->results['scenarios']['failed'],
      $this->results['scenarios']['skipped']);
    $this->text[] = sprintf(
      "Steps:     %d ran, %d passed, %d failed, %d skipped, %d pending, %d missing.",
      $this->results['steps']['passed'] + $this->results['steps']['failed'],
      $this->results['steps']['passed'],
      $this->results['steps']['failed'],
      $this->results['steps']['skipped'],
      $this->results['steps']['pending'],
      $this->results['steps']['missing']);
    $this->notify();
  }

  function assert_handler($file, $line, $code) {
    $this->results['steps']['failed']++;
    $this->results['scenarios']['failed']++;
    $this->results['features']['failed']++;
    $this->status = self::STEP_FAILED;
    $this->error = "Failed assertion on line {$line} of {$file}: {$code}";
  }

}

class StoryStep {
  private $step_files;
  private $step;
  private $matched;

  function __construct($step_dir = 'features/steps') {
    $this->step_files = glob("{$step_dir}/*.php");
  }

  function match($step, $context = array()) {
    $this->step = $step;
    $this->matched = FALSE;
    $step = $this;
    extract($context);
    if($this->step_files) foreach($this->step_files as $step_file) {
      if((include $step_file) == 'pending') return 'pending';
      if($this->matched) return TRUE;
    }
  }

  function is($step_definition) {
    $re = '/' . preg_replace('/"(.*)"/U', '(?P<$1>.*)', preg_quote($step_definition, '/')) . '/';
    if(preg_match($re, $this->step, $matches)) {
      foreach($matches as $k => $v) if(!is_numeric($k)) $this->$k = substr($v, 1, -1);
      return $this->matched = TRUE;
    }
  }
}

abstract class StoryRunnerReporter implements SplObserver {
  function update(SplSubject $subject) {
    $callback = $subject->get_status();
    $this->$callback($subject->get_text(), $subject->get_error());
  }

  abstract function feature_text($text, $error);
  abstract function scenario_text($text, $error);
  abstract function step_passed($text, $error);
  abstract function step_failed($text, $error);
  abstract function step_skipped($text, $error);
  abstract function step_missing($text, $error);
  abstract function step_pending($text, $error);
  abstract function story_runner_done($text, $error);
  abstract function story_runner_failed($text, $error);
}

class StoryRunnerCliReporter extends StoryRunnerReporter {

  private function output($text, $indent = 0) {
    $lines = (array)$text;
    $leading_space = $indent ? implode('', array_fill(0, $indent * 2, ' ')) : '';
    foreach($lines as $i => $line) if(is_numeric($i)) {
      if(is_array($line)) {
        $this->output($line, $indent);
      } else {
        echo $leading_space . $line . PHP_EOL;
      }
    }
  }

  function feature_text($text, $error){
    array_unshift($text, '');
    $this->output($text);
  }

  function scenario_text($text, $error){
    array_unshift($text, '');
    $this->output($text, 1);
  }

  function step_passed($text, $error){
    $this->output($text[0], 2);
  }

  function step_failed($text, $error){
    $this->output(array('', '[failed] ' . $text[0], $error[0], ''), 2);
  }

  function step_skipped($text, $error){
    $this->output('[skipped] ' . $text[0], 2);
  }

  function step_missing($text, $error){
    $this->output('[missing] ' . $text[0], 2);
  }

  function step_pending($text, $error){
    $this->output('[pending] ' . $text[0], 2);
  }

  function story_runner_done($text, $error){
    array_unshift($text, '');
    $this->output($text);
  }

  function story_runner_failed($text, $error){
    $this->output('Bailing: ' . $error[0]);
  }
}

/**
 * @TODO:
 * tags
 * hooks
 * multiline steps - denote with trailing colon
 * scenario outlines
 * comments easy


 hooks

 before all|feature|scenario|step
 after all|feature|scenario|step

 a separate include for each in support/hooks
 run in context - include pattern

 tags

 this is harder - extend the parser
 cli options
 

 */