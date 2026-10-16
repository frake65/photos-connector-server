<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\Controller;

use OCA\ApplePhotosConnector\AppInfo\Application;
use OCA\ApplePhotosConnector\Service\MetadataCatalogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/** Opt-in, owner-scoped metadata catalog API. */
final class MetadataCatalogController extends Controller {
    public function __construct(IRequest $request,private IUserSession $session,private MetadataCatalogService $catalog){
        parent::__construct(Application::APP_ID,$request);
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function start(): JSONResponse {
        $user=$this->user();if($user instanceof JSONResponse)return $user;
        if(($bad=$this->requireJson())!==null)return $bad;
        $folderId=$this->request->getParam('folderId');
        if((!is_int($folderId)&&!is_string($folderId))||!preg_match('/^[0-9]+$/D',(string)$folderId)||(int)$folderId<1)return $this->failure(400,'invalid_folder_id');
        try{return new JSONResponse(['scan'=>$this->catalog->start($user,(int)$folderId)],202);}
        catch(\DomainException){return $this->failure(404,'folder_unavailable');}
        catch(\InvalidArgumentException){return $this->failure(400,'invalid_folder_id');}
        catch(\Throwable){return $this->failure(500,'catalog_scan_start_failed');}
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function status(string $scanId): JSONResponse {
        $user=$this->user();if($user instanceof JSONResponse)return $user;
        if(!preg_match('/^[0-9a-f-]{36}$/iD',$scanId))return $this->failure(400,'invalid_scan_id');
        try{$scan=$this->catalog->get($user,$scanId);return $scan===null?$this->failure(404,'scan_not_found'):new JSONResponse(['scan'=>$scan]);}
        catch(\Throwable){return $this->failure(500,'catalog_scan_status_failed');}
    }
    private function user(): string|JSONResponse {
        $user=$this->session->getUser();
        if($user===null||!str_starts_with(strtolower($this->request->getHeader('Authorization')),'basic '))return $this->failure(401,'authentication_required');
        return $user->getUID();
    }
    private function requireJson(): ?JSONResponse {
        $type=strtolower(trim(explode(';',$this->request->getHeader('Content-Type'))[0]));
        return $type==='application/json'?null:$this->failure(415,'json_required');
    }
    private function failure(int $status,string $code): JSONResponse{return new JSONResponse(['error'=>['code'=>$code]],$status);}
}
