<?php

#error_reporting(E_ALL);
#ini_set("display_errors", 1);

/**
 * i-Educar - Sistema de gestão escolar
 *
 * Copyright (C) 2006  Prefeitura Municipal de Itajaí
 *     <ctima@itajai.sc.gov.br>
 *
 * Este programa é software livre; você pode redistribuí-lo e/ou modificá-lo
 * sob os termos da Licença Pública Geral GNU conforme publicada pela Free
 * Software Foundation; tanto a versão 2 da Licença, como (a seu critério)
 * qualquer versão posterior.
 *
 * Este programa é distribuí­do na expectativa de que seja útil, porém, SEM
 * NENHUMA GARANTIA; nem mesmo a garantia implí­cita de COMERCIABILIDADE OU
 * ADEQUAÇÃO A UMA FINALIDADE ESPECÍFICA. Consulte a Licença Pública Geral
 * do GNU para mais detalhes.
 *
 * Você deve ter recebido uma cópia da Licença Pública Geral do GNU junto
 * com este programa; se não, escreva para a Free Software Foundation, Inc., no
 * endereço 59 Temple Street, Suite 330, Boston, MA 02111-1307 USA.
 *
 * @author    Lucas D'Avila <lucasdavila@portabilis.com.br>
 * @category  i-Educar
 * @license   @@license@@
 * @package   Api
 * @subpackage  Modules
 * @since   Arquivo disponível desde a versão ?
 * @version   $Id$
 */

require_once 'lib/Portabilis/Controller/ApiCoreController.php';
require_once 'include/pmieducar/clsPmieducarMatriculaTurma.inc.php';
require_once 'Avaliacao/Service/Boletim.php';
require_once 'lib/Portabilis/Array/Utils.php';
require_once 'lib/Portabilis/String/Utils.php';
require_once "Reports/Reports/BoletimReport.php";

// #TODO migrar controlador para novo padrão (um para cada resource)
//       ao migrar metodos aluno para AlunoController, migrar search para novo padrão.

class V1Controller extends ApiCoreController
{
  protected $_dataMapper  = null;

  #TODO definir este valor com mesmo código cadastro de tipo de exemplar?
  protected $_processoAp  = 0;
  protected $_nivelAcessoOption = App_Model_NivelAcesso::SOMENTE_ESCOLA;
  protected $_saveOption  = FALSE;
  protected $_deleteOption  = FALSE;
  protected $_titulo   = '';


  protected function validatesUserIsLoggedIn() {

    #FIXME validar tokens API
    return true;
  }


  protected function canAcceptRequest() {
    return parent::canAcceptRequest() &&
           $this->validatesPresenceOf('escola_id') &&
           $this->validatesExistenceOf('escola', $this->getRequest()->escola_id);
  }


  protected function canGetAluno() {
    return $this->canAcceptRequest() &&
           $this->validatesPresenceOf('aluno_id') &&
           $this->validatesExistenceOf('aluno', $this->getRequest()->aluno_id, array('add_msg_on_error' => false));
  }


  protected function canGetAlunoSearch() {
    return $this->canAcceptRequest() &&
           $this->validatesPresenceOf('query');
  }


  protected function canGetMatricula() {
    return $this->canAcceptRequest() &&
           $this->validatesPresenceOf(array('matricula_id', 'escola_id')) &&
           $this->validatesExistenceOf('matricula', $this->getRequest()->matricula_id);
  }


  protected function canGetOcorrenciasDisciplinares() {
    return $this->canAcceptRequest() &&
           $this->validatesPresenceOf('aluno_id') &&
           $this->validatesExistenceOf('aluno', $this->getRequest()->aluno_id);
  }


  protected function canGetRelatorioBoletim() {
    return $this->canAcceptRequest() &&
           $this->validatesPresenceOf(array('matricula_id', 'escola_id')) &&
           $this->validatesExistenceOf('matricula', $this->getRequest()->matricula_id);
  }


  protected function serviceBoletimForMatricula($id) {
    $service = null;

    # FIXME get $this->getSession()->id_pessoa se usuario logado
    # ou pegar id do ini config, se api request
    $userId = 1;

    try {
      $service = new Avaliacao_Service_Boletim(array('matricula' => $id, 'usuario' => $userId));
    }
    catch (Exception $e){
      $this->messenger->append("Erro ao instanciar serviço boletim para matricula {$id}: " . $e->getMessage());
    }

    return $service;
  }


  // load resources

  protected function loadNomeEscola() {
    $sql = "select nome from cadastro.pessoa, pmieducar.escola where idpes = ref_idpes and cod_escola = $1";
    $nome = $this->fetchPreparedQuery($sql, $this->getRequest()->escola_id, false, 'first-field');

    return $this->safeString($nome);
  }


  protected function loadNomeAluno($alunoId = null) {
    if (is_null($alunoId))
      $alunoId = $this->getRequest()->aluno_id;

    $sql = "select nome from cadastro.pessoa, pmieducar.aluno where idpes = ref_idpes and cod_aluno = $1";
    $nome = $this->fetchPreparedQuery($sql, $alunoId, false, 'first-field');

    return $this->safeString($nome);
  }


  protected function loadNameFor($resourceName, $id){
    $sql = "select nm_{$resourceName} from pmieducar.{$resourceName} where cod_{$resourceName} = $1";
    $nome = $this->fetchPreparedQuery($sql, $id, false, 'first-field');

    return $this->safeString($nome);
  }


  protected function tryLoadMatriculaTurma($matricula) {
    $sql            = "select ref_cod_turma as turma_id, turma.tipo_boletim from pmieducar.matricula_turma, pmieducar.turma where ref_cod_turma = cod_turma and ref_cod_matricula = $1 and matricula_turma.ativo = 1 limit 1";

    $matriculaTurma = $this->fetchPreparedQuery($sql, $matricula['id'], false, 'first-row');

    if (is_array($matriculaTurma) and count($matriculaTurma) > 0) {
      $attrs                                     = array('turma_id', 'tipo_boletim');

      $matriculaTurma                            = Portabilis_Array_Utils::filter($matriculaTurma, $attrs);
      $matriculaTurma['nome_turma']              = $this->loadNameFor('turma', $matriculaTurma['turma_id']);
    }

    return $matriculaTurma;
  }


  // carrega dados matricula (instituicao_id, escola_id, curso_id, serie_id e (first) turma_id, ano) de uma matricula.
  protected function loadDadosForMatricula($matriculaId){
    $sql            = "select cod_matricula as id, ref_cod_aluno as aluno_id, matricula.ano, escola.ref_cod_instituicao as instituicao_id, matricula.ref_ref_cod_escola as escola_id, matricula.ref_cod_curso as curso_id, matricula.ref_ref_cod_serie as serie_id, matricula_turma.ref_cod_turma as turma_id from pmieducar.matricula_turma, pmieducar.matricula, pmieducar.escola where escola.cod_escola = matricula.ref_ref_cod_escola and ref_cod_matricula = cod_matricula and ref_cod_matricula = $1 and matricula.ativo = matricula_turma.ativo and matricula_turma.ativo = 1 order by matricula_turma.sequencial limit 1";

    $params         = array($matriculaId);
    $dadosMatricula = $this->fetchPreparedQuery($sql, $params, false, 'first-row');

    // filtra apenas chaves abaixo, deixando de fora os indices.
    $attrs          = array('id', 'aluno_id', 'ano', 'instituicao_id', 'escola_id', 'curso_id', 'serie_id', 'turma_id');
    $dadosMatricula = Portabilis_Array_Utils::filter($dadosMatricula, $attrs);

    return $dadosMatricula;
  }


  protected function loadMatriculasAluno() {
    // #TODO mostrar o nome da situação da matricula

    // seleciona somente matriculas em andamento, aprovado, reprovado, em exame, aprovado apos exame e retido faltas
    $sql = "select cod_matricula as id, ano, ref_cod_instituicao as instituicao_id, ref_ref_cod_escola as escola_id,
           ref_cod_curso as curso_id, ref_ref_cod_serie as serie_id from pmieducar.matricula, pmieducar.escola where
           cod_escola = ref_ref_cod_escola and ref_cod_aluno = $1 and ref_ref_cod_escola = $2 and matricula.ativo = 1 and
           matricula.aprovado in (1, 2, 3, 7, 8, 9) order by ano desc, id";

    $params     = array($this->getRequest()->aluno_id, $this->getRequest()->escola_id);
    $matriculas = $this->fetchPreparedQuery($sql, $params, false);

    if (is_array($matriculas) && count($matriculas) > 0) {
      $attrs      = array('id', 'ano', 'instituicao_id', 'escola_id', 'curso_id', 'serie_id');
      $matriculas = Portabilis_Array_Utils::filterSet($matriculas, $attrs);

      foreach($matriculas as $key => $matricula) {
        $matriculas[$key]['nome_curso']                = $this->loadNameFor('curso', $matricula['curso_id']);
        $matriculas[$key]['nome_escola']               = $this->loadNomeEscola();
        $matriculas[$key]['nome_serie']                = $this->loadNameFor('serie', $matricula['serie_id']);
        $matriculas[$key]['situacao']                  = '#TODO';
        $turma                                         = $this->tryLoadMatriculaTurma($matricula);

        if (is_array($turma) and count($turma) > 0) {
          $matriculas[$key]['turma_id']                = $turma['turma_id'];
          $matriculas[$key]['nome_turma']              = $turma['nome_turma'];
          $matriculas[$key]['report_boletim_template'] = $turma['report_boletim_template'];
        }
      }
    }

    return $matriculas;
  }


  protected function loadTipoOcorrenciaDisciplinar($id) {
    if (! isset($this->_tiposOcorrenciasDisciplinares))
      $this->_tiposOcorrenciasDisciplinares = array();

    if (! isset($this->_tiposOcorrenciasDisciplinares[$id])) {
      $ocorrencia                                  = new clsPmieducarTipoOcorrenciaDisciplinar;
      $ocorrencia->cod_tipo_ocorrencia_disciplinar = $id;
      $ocorrencia                                  = $ocorrencia->detalhe();

      $this->_tiposOcorrenciasDisciplinares[$id]   = utf8_encode($ocorrencia['nm_tipo']);
    }

    return $this->_tiposOcorrenciasDisciplinares[$id];
  }


  protected function loadOcorrenciasDisciplinares() {
    $ocorrenciasAluno              = array();
    $matriculas                    = $this->loadMatriculasAluno();

    $attrsFilter                   = array('ref_cod_tipo_ocorrencia_disciplinar' => 'tipo',
                                           'data_cadastro'                       => 'data_hora',
                                           'observacao'                          => 'descricao');

    $ocorrenciasMatriculaInstance  = new clsPmieducarMatriculaOcorrenciaDisciplinar();

    foreach($matriculas as $matricula) {
      $ocorrenciasMatricula = $ocorrenciasMatriculaInstance->lista($matricula['id'],
                                                                    null,
                                                                    null,
                                                                    null,
                                                                    null,
                                                                    null,
                                                                    null,
                                                                    null,
                                                                    null,
                                                                    null,
                                                                    1,
                                                                    $visivel_pais = 1);

      if (is_array($ocorrenciasMatricula)) {
        $ocorrenciasMatricula = Portabilis_Array_Utils::filterSet($ocorrenciasMatricula, $attrsFilter);

        foreach($ocorrenciasMatricula as $ocorrenciaMatricula) {
          $ocorrenciaMatricula['tipo']      = $this->loadTipoOcorrenciaDisciplinar($ocorrenciaMatricula['tipo']);
          $ocorrenciaMatricula['data_hora'] = date('d/m/Y H:i:s', strtotime($ocorrenciaMatricula['data_hora']));
          $ocorrenciaMatricula['descricao'] = utf8_encode($ocorrenciaMatricula['descricao']);
          $ocorrenciasAluno[]               = $ocorrenciaMatricula;
        }
      }
    }

    return $ocorrenciasAluno;
  }


  protected function loadAlunosBySearchQuery($query, $escolaId) {
    $alunos       = array();
    $search_items = Portabilis_String_Utils::split(array('-', ' '), $query, array('limit' => 2));

    // seleciona somente matriculas em andamento, aprovado, reprovado, em exame, aprovado apos exame e retido faltas

    if (is_numeric($search_items[0])) {
      $sql = "select distinct aluno.cod_aluno as id from pmieducar.matricula, pmieducar.aluno
              where aluno.cod_aluno = matricula.ref_cod_aluno and aluno.ativo = matricula.ativo
              and matricula.ativo = 1 and matricula.ref_ref_cod_escola = $1 and
              (matricula.cod_matricula = $2 or matricula.ref_cod_aluno = $2) and
              matricula.aprovado in (1, 2, 3, 7, 8, 9) limit 15";

      $params  = array($escolaId, $search_items[0]);
    }
    else {
      $sql = "select distinct aluno.cod_aluno as id, pessoa.nome as a from pmieducar.matricula,
              pmieducar.aluno, cadastro.pessoa where aluno.cod_aluno = matricula.ref_cod_aluno and
              aluno.ativo = matricula.ativo and matricula.ativo = 1 and matricula.ref_ref_cod_escola = $1 and
              pessoa.idpes = aluno.ref_idpes and lower(pessoa.nome) like $2 and
              matricula.aprovado in (1, 2, 3, 7, 8, 9) order by nome limit 15";

      $params  = array($escolaId, strtolower($query) ."%");
    }

    $_alunos = $this->fetchPreparedQuery($sql, $params, false);

    foreach($_alunos as $aluno) {
      $id   = $aluno['id'];
      $nome = isset($aluno['nome']) ? $aluno['nome'] : $this->loadNomeAluno($id);

      $alunos[$id] = "$id - $nome";
    }

    return $alunos;
  }


  // api responders

  protected function getAluno() {
    if ($this->canGetAluno()) {
      return array('id'         => $this->getRequest()->aluno_id,
                   'nome'       => $this->loadNomeAluno(),
                   'matriculas' => $this->loadMatriculasAluno(true));
    }
  }

  protected function getAlunoSearch() {
      $alunos = array();

      if ($this->canGetAlunoSearch())
        $alunos = $this->loadAlunosBySearchQuery($this->getRequest()->query, $this->getRequest()->escola_id);

      return array('result' => $alunos);
    }

  protected function getMatricula() {
    if ($this->canGetMatricula())
      return $this->loadDadosForMatricula($this->getRequest()->matricula_id);
  }


  protected function getOcorrenciasDisciplinares() {
    if ($this->canGetOcorrenciasDisciplinares())
      return $this->loadOcorrenciasDisciplinares();
  }


  protected function getRelatorioBoletim() {
    if ($this->canGetRelatorioBoletim()) {
      $dadosMatricula = $this->loadDadosForMatricula($this->getRequest()->matricula_id);

      $boletimReport = new BoletimReport();

      $boletimReport->addArg('matricula',   (int)$dadosMatricula['id']);
      $boletimReport->addArg('ano',         (int)$dadosMatricula['ano']);
      $boletimReport->addArg('instituicao', (int)$dadosMatricula['instituicao_id']);
      $boletimReport->addArg('escola',      (int)$dadosMatricula['escola_id']);
      $boletimReport->addArg('curso',       (int)$dadosMatricula['curso_id']);
      $boletimReport->addArg('serie',       (int)$dadosMatricula['serie_id']);
      $boletimReport->addArg('turma',       (int)$dadosMatricula['turma_id']);

      $encoding     = 'base64';

      $dumpsOptions = array('options' => array('encoding' => $encoding));
      $encoded      = $boletimReport->dumps($dumpsOptions);

      return array('matricula_id' => $this->getRequest()->matricula_id,
                   'encoding'     => $encoding,
                   'encoded'      => $encoded);
    }
  }


  public function Gerar() {
    if ($this->isRequestFor('get', 'aluno'))
      $this->appendResponse('aluno', $this->getAluno());

    
    // TODO migrar clientes para usar search-aluno da API Aluno(Controller) 
    elseif ($this->isRequestFor('get', 'aluno-search'))
      $this->appendResponse('aluno-search', $this->getAlunoSearch());

    elseif ($this->isRequestFor('get', 'matricula'))
      $this->appendResponse('matricula', $this->getMatricula());

    elseif ($this->isRequestFor('get', 'ocorrencias_disciplinares'))
      $this->appendResponse('ocorrencias_disciplinares', $this->getOcorrenciasDisciplinares());

    elseif ($this->isRequestFor('get', 'relatorio_boletim'))
      $this->appendResponse('relatorio_boletim', $this->getRelatorioBoletim());

    else
      $this->notImplementedOperationError();
  }
}
