taleo
=====

A wordpress plugin to integrate Taleo Job Listings 


## Installation 
1. Place Taleo folder in plugins folder in wordpress
2. Activate Plugin 
3. In the wordpress admin panel click settings , then put in UN/PW and company ID

## Usage 
To Add new jobs from Taleo into wordpress 
1. In the wordpress admin panel click settings, then click add taleo jobs button 

### Programmatically Add Jobs

 ``` php
 
$taleo = new Agt_taleo();
$taleo->manually_sync_jobs();

  ```
