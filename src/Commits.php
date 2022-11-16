<?php

declare(strict_types=1);

namespace ReleaseNotes;

use Gitlab\Api\AbstractApi;

class Commits extends AbstractApi
{
  /**
   * @param int|string $project_id
   * @param array      $parameters {
   *
   *     @var string $order_by Return tags ordered by `name` or `updated` fields. Default is `updated`.
   *     @var string $sort     Return tags sorted in asc or desc order. Default is desc.
   *     @var string $search   Return list of tags matching the search criteria. You can use `^term` and `term$` to
   *                           find tags that begin and end with term respectively.
   * }
   *
   * @return mixed
   */
  public function all($project_id, array $parameters = [])
  {
    $resolver = $this->createOptionsResolver();
    $resolver->setDefined('ref_name');
    $resolver->setDefined('since');

    return $this->get($this->getProjectPath($project_id, 'repository/commits'), $resolver->resolve($parameters));
  }

  /**
   * @param int|string $project_id
   * @param string     $tag_name
   *
   * @return mixed
   */
  public function show($project_id, string $hash)
  {
    return $this->get($this->getProjectPath($project_id, 'repository/commits/'.self::encodePath($hash)));
  }

}
