--
-- PostgreSQL database dump
--


-- Dumped from database version 15.14 (Debian 15.14-1.pgdg12+1)
-- Dumped by pg_dump version 15.14 (Debian 15.14-1.pgdg12+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: cnj; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA cnj;


--
-- Name: pulse; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA pulse;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: assuntos; Type: TABLE; Schema: cnj; Owner: -
--

CREATE TABLE cnj.assuntos (
    id bigint NOT NULL,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    codigo integer NOT NULL,
    descricao character varying(255) NOT NULL,
    codigo_pai integer,
    tem_filhos boolean DEFAULT false NOT NULL,
    situacao character varying(2) NOT NULL
);


--
-- Name: assuntos_id_seq; Type: SEQUENCE; Schema: cnj; Owner: -
--

CREATE SEQUENCE cnj.assuntos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: assuntos_id_seq; Type: SEQUENCE OWNED BY; Schema: cnj; Owner: -
--

ALTER SEQUENCE cnj.assuntos_id_seq OWNED BY cnj.assuntos.id;


--
-- Name: classes; Type: TABLE; Schema: cnj; Owner: -
--

CREATE TABLE cnj.classes (
    id bigint NOT NULL,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    codigo integer NOT NULL,
    descricao character varying(255) NOT NULL,
    codigo_pai integer,
    tem_filhos boolean DEFAULT false NOT NULL,
    situacao character varying(2) NOT NULL
);


--
-- Name: classes_id_seq; Type: SEQUENCE; Schema: cnj; Owner: -
--

CREATE SEQUENCE cnj.classes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: classes_id_seq; Type: SEQUENCE OWNED BY; Schema: cnj; Owner: -
--

ALTER SEQUENCE cnj.classes_id_seq OWNED BY cnj.classes.id;


--
-- Name: orgaos; Type: TABLE; Schema: cnj; Owner: -
--

CREATE TABLE cnj.orgaos (
    id bigint NOT NULL,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    codigo integer NOT NULL,
    nome character varying(255) NOT NULL,
    tribunal_id integer
);


--
-- Name: orgaos_id_seq; Type: SEQUENCE; Schema: cnj; Owner: -
--

CREATE SEQUENCE cnj.orgaos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: orgaos_id_seq; Type: SEQUENCE OWNED BY; Schema: cnj; Owner: -
--

ALTER SEQUENCE cnj.orgaos_id_seq OWNED BY cnj.orgaos.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: documentos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.documentos (
    id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    usuario_criacao_id integer NOT NULL,
    tipo_documento_codigo integer,
    unidade_id integer NOT NULL,
    mimetype character varying(255) NOT NULL,
    nivel_sigilo integer NOT NULL,
    conteudo_html text,
    url character varying(255),
    path character varying(255),
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


--
-- Name: documentos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.documentos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: documentos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.documentos_id_seq OWNED BY public.documentos.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: knowledge_base_sequence_job_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.knowledge_base_sequence_job_seq
    START WITH 10
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: notificacoes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notificacoes (
    id bigint NOT NULL,
    processo_id bigint NOT NULL,
    notificacao_id bigint NOT NULL,
    tipo character varying(255) NOT NULL,
    notificado boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: notificacoes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notificacoes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notificacoes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notificacoes_id_seq OWNED BY public.notificacoes.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: processo_assuntos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.processo_assuntos (
    id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    processo_id integer NOT NULL,
    assunto_codigo character varying(255) NOT NULL,
    nome character varying(255),
    principal boolean DEFAULT false NOT NULL
);


--
-- Name: processo_assuntos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.processo_assuntos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: processo_assuntos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.processo_assuntos_id_seq OWNED BY public.processo_assuntos.id;


--
-- Name: processo_documentos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.processo_documentos (
    id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    processo_id bigint NOT NULL,
    id_documento character varying(255) NOT NULL,
    id_documento_vinculado character varying(255),
    tipo_documento character varying(255) NOT NULL,
    data_hora timestamp(0) without time zone NOT NULL,
    mimetype character varying(255) NOT NULL,
    movimento character varying(255),
    hash character varying(255),
    nivel_sigilo character varying(255) DEFAULT '0'::character varying NOT NULL,
    descricao character varying(255) NOT NULL,
    url character varying(255),
    path character varying(255),
    status character varying(255) DEFAULT 'pendente'::character varying NOT NULL,
    tentativas_download integer DEFAULT 0 NOT NULL,
    conteudo_html text,
    file_size bigint,
    usuario_juntada_arquivo character varying(255),
    data_juntada timestamp(0) without time zone
);


--
-- Name: processo_documentos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.processo_documentos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: processo_documentos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.processo_documentos_id_seq OWNED BY public.processo_documentos.id;


--
-- Name: processo_exportacoes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.processo_exportacoes (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    numero_processo character varying(25) NOT NULL,
    tribunal_id bigint,
    titulo character varying(255) NOT NULL,
    formato character varying(10) NOT NULL,
    status character varying(255) DEFAULT 'enfileirado'::character varying NOT NULL,
    uuid_arquivo uuid,
    s3_path character varying(500),
    tamanho_bytes bigint,
    erro_resumo text,
    filtros json NOT NULL,
    webhook_enviado_em timestamp(0) without time zone,
    webhook_tentativas smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT processo_exportacoes_status_check CHECK (((status)::text = ANY ((ARRAY['enfileirado'::character varying, 'processando'::character varying, 'concluido'::character varying, 'falhou'::character varying])::text[])))
);


--
-- Name: processo_exportacoes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.processo_exportacoes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: processo_exportacoes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.processo_exportacoes_id_seq OWNED BY public.processo_exportacoes.id;


--
-- Name: processo_movimentos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.processo_movimentos (
    id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    processo_id bigint NOT NULL,
    identificador_movimento character varying(255) NOT NULL,
    codigo_nacional character varying(255),
    complemento text,
    data_hora timestamp(0) without time zone NOT NULL,
    id_documento_vinculado character varying(255)
);


--
-- Name: processo_movimentos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.processo_movimentos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: processo_movimentos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.processo_movimentos_id_seq OWNED BY public.processo_movimentos.id;


--
-- Name: processo_parte_representante; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.processo_parte_representante (
    id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    processo_id bigint NOT NULL,
    parte_id bigint NOT NULL,
    nome character varying(255) NOT NULL,
    numero_documento_principal character varying(255),
    inscricao character varying(255),
    tipo_representante character varying(255)
);


--
-- Name: processo_parte_representante_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.processo_parte_representante_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: processo_parte_representante_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.processo_parte_representante_id_seq OWNED BY public.processo_parte_representante.id;


--
-- Name: processo_partes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.processo_partes (
    id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    polo character varying(255),
    processo_id integer NOT NULL,
    nome character varying(255) NOT NULL,
    cpf_cnpj character varying(255),
    cep character varying(255),
    logradouro character varying(255),
    numero character varying(255),
    bairro character varying(255),
    municipio character varying(255),
    estado character varying(255)
);


--
-- Name: processo_partes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.processo_partes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: processo_partes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.processo_partes_id_seq OWNED BY public.processo_partes.id;


--
-- Name: processo_prioridades; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.processo_prioridades (
    id bigint NOT NULL,
    processo_id integer NOT NULL,
    descricao character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: processo_prioridades_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.processo_prioridades_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: processo_prioridades_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.processo_prioridades_id_seq OWNED BY public.processo_prioridades.id;


--
-- Name: processos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.processos (
    id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    tribunal_id integer NOT NULL,
    numero_processo character varying(255),
    classe_codigo character varying(255),
    assunto_codigo character varying(255),
    competencia_codigo character varying(255),
    prioridade character varying(255),
    valor_causa numeric(15,2) NOT NULL,
    nivel_sigilo character varying(255),
    status character varying(255) DEFAULT 'Pendente de envio'::character varying NOT NULL,
    justica_gratuita character varying(255),
    pedido_liminar character varying(255),
    motivo_segredo_justica character varying(255),
    tentantivas_envio integer DEFAULT 1 NOT NULL,
    payload_envio text,
    tipo_eleicao character varying(255),
    jurisdicao_codigo character varying(10),
    nome_orgao_julgador character varying(255),
    codigo_orgao_julgador character varying(255),
    instancia_orgao_julgador character varying(255),
    embedding_status character varying(255) DEFAULT 'PENDING'::character varying NOT NULL,
    embedding_completed_at timestamp(0) without time zone
);


--
-- Name: processos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.processos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: processos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.processos_id_seq OWNED BY public.processos.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: tribunais; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tribunais (
    id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    nome character varying(255) NOT NULL,
    login character varying(255) NOT NULL,
    password character varying(255) NOT NULL,
    url_webservice_mni character varying(255) NOT NULL,
    url_webservice_mni_complementar character varying(255) NOT NULL,
    url_consulta_pje character varying(255),
    tipo character varying(255),
    ativo boolean,
    url_recuperar_senha character varying(255),
    uuid uuid
);


--
-- Name: tribunais_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tribunais_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tribunais_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tribunais_id_seq OWNED BY public.tribunais.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: pulse_aggregates; Type: TABLE; Schema: pulse; Owner: -
--

CREATE TABLE pulse.pulse_aggregates (
    id bigint NOT NULL,
    bucket integer NOT NULL,
    period integer NOT NULL,
    type character varying(255) NOT NULL,
    key text NOT NULL,
    key_hash uuid GENERATED ALWAYS AS ((md5(key))::uuid) STORED NOT NULL,
    aggregate character varying(255) NOT NULL,
    value numeric(20,2) NOT NULL,
    count integer
);


--
-- Name: pulse_aggregates_id_seq; Type: SEQUENCE; Schema: pulse; Owner: -
--

CREATE SEQUENCE pulse.pulse_aggregates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pulse_aggregates_id_seq; Type: SEQUENCE OWNED BY; Schema: pulse; Owner: -
--

ALTER SEQUENCE pulse.pulse_aggregates_id_seq OWNED BY pulse.pulse_aggregates.id;


--
-- Name: pulse_entries; Type: TABLE; Schema: pulse; Owner: -
--

CREATE TABLE pulse.pulse_entries (
    id bigint NOT NULL,
    "timestamp" integer NOT NULL,
    type character varying(255) NOT NULL,
    key text NOT NULL,
    key_hash uuid GENERATED ALWAYS AS ((md5(key))::uuid) STORED NOT NULL,
    value bigint
);


--
-- Name: pulse_entries_id_seq; Type: SEQUENCE; Schema: pulse; Owner: -
--

CREATE SEQUENCE pulse.pulse_entries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pulse_entries_id_seq; Type: SEQUENCE OWNED BY; Schema: pulse; Owner: -
--

ALTER SEQUENCE pulse.pulse_entries_id_seq OWNED BY pulse.pulse_entries.id;


--
-- Name: pulse_values; Type: TABLE; Schema: pulse; Owner: -
--

CREATE TABLE pulse.pulse_values (
    id bigint NOT NULL,
    "timestamp" integer NOT NULL,
    type character varying(255) NOT NULL,
    key text NOT NULL,
    key_hash uuid GENERATED ALWAYS AS ((md5(key))::uuid) STORED NOT NULL,
    value text NOT NULL
);


--
-- Name: pulse_values_id_seq; Type: SEQUENCE; Schema: pulse; Owner: -
--

CREATE SEQUENCE pulse.pulse_values_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pulse_values_id_seq; Type: SEQUENCE OWNED BY; Schema: pulse; Owner: -
--

ALTER SEQUENCE pulse.pulse_values_id_seq OWNED BY pulse.pulse_values.id;


--
-- Name: assuntos id; Type: DEFAULT; Schema: cnj; Owner: -
--

ALTER TABLE ONLY cnj.assuntos ALTER COLUMN id SET DEFAULT nextval('cnj.assuntos_id_seq'::regclass);


--
-- Name: classes id; Type: DEFAULT; Schema: cnj; Owner: -
--

ALTER TABLE ONLY cnj.classes ALTER COLUMN id SET DEFAULT nextval('cnj.classes_id_seq'::regclass);


--
-- Name: orgaos id; Type: DEFAULT; Schema: cnj; Owner: -
--

ALTER TABLE ONLY cnj.orgaos ALTER COLUMN id SET DEFAULT nextval('cnj.orgaos_id_seq'::regclass);


--
-- Name: documentos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documentos ALTER COLUMN id SET DEFAULT nextval('public.documentos_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: notificacoes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notificacoes ALTER COLUMN id SET DEFAULT nextval('public.notificacoes_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: processo_assuntos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_assuntos ALTER COLUMN id SET DEFAULT nextval('public.processo_assuntos_id_seq'::regclass);


--
-- Name: processo_documentos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_documentos ALTER COLUMN id SET DEFAULT nextval('public.processo_documentos_id_seq'::regclass);


--
-- Name: processo_exportacoes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_exportacoes ALTER COLUMN id SET DEFAULT nextval('public.processo_exportacoes_id_seq'::regclass);


--
-- Name: processo_movimentos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_movimentos ALTER COLUMN id SET DEFAULT nextval('public.processo_movimentos_id_seq'::regclass);


--
-- Name: processo_parte_representante id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_parte_representante ALTER COLUMN id SET DEFAULT nextval('public.processo_parte_representante_id_seq'::regclass);


--
-- Name: processo_partes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_partes ALTER COLUMN id SET DEFAULT nextval('public.processo_partes_id_seq'::regclass);


--
-- Name: processo_prioridades id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_prioridades ALTER COLUMN id SET DEFAULT nextval('public.processo_prioridades_id_seq'::regclass);


--
-- Name: processos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processos ALTER COLUMN id SET DEFAULT nextval('public.processos_id_seq'::regclass);


--
-- Name: tribunais id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tribunais ALTER COLUMN id SET DEFAULT nextval('public.tribunais_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: pulse_aggregates id; Type: DEFAULT; Schema: pulse; Owner: -
--

ALTER TABLE ONLY pulse.pulse_aggregates ALTER COLUMN id SET DEFAULT nextval('pulse.pulse_aggregates_id_seq'::regclass);


--
-- Name: pulse_entries id; Type: DEFAULT; Schema: pulse; Owner: -
--

ALTER TABLE ONLY pulse.pulse_entries ALTER COLUMN id SET DEFAULT nextval('pulse.pulse_entries_id_seq'::regclass);


--
-- Name: pulse_values id; Type: DEFAULT; Schema: pulse; Owner: -
--

ALTER TABLE ONLY pulse.pulse_values ALTER COLUMN id SET DEFAULT nextval('pulse.pulse_values_id_seq'::regclass);


--
-- Name: assuntos assuntos_pkey; Type: CONSTRAINT; Schema: cnj; Owner: -
--

ALTER TABLE ONLY cnj.assuntos
    ADD CONSTRAINT assuntos_pkey PRIMARY KEY (id);


--
-- Name: classes classes_pkey; Type: CONSTRAINT; Schema: cnj; Owner: -
--

ALTER TABLE ONLY cnj.classes
    ADD CONSTRAINT classes_pkey PRIMARY KEY (id);


--
-- Name: orgaos orgaos_pkey; Type: CONSTRAINT; Schema: cnj; Owner: -
--

ALTER TABLE ONLY cnj.orgaos
    ADD CONSTRAINT orgaos_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: documentos documentos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documentos
    ADD CONSTRAINT documentos_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: notificacoes notificacoes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notificacoes
    ADD CONSTRAINT notificacoes_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: processo_assuntos processo_assuntos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_assuntos
    ADD CONSTRAINT processo_assuntos_pkey PRIMARY KEY (id);


--
-- Name: processo_documentos processo_documentos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_documentos
    ADD CONSTRAINT processo_documentos_pkey PRIMARY KEY (id);


--
-- Name: processo_exportacoes processo_exportacoes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_exportacoes
    ADD CONSTRAINT processo_exportacoes_pkey PRIMARY KEY (id);


--
-- Name: processo_movimentos processo_movimentos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_movimentos
    ADD CONSTRAINT processo_movimentos_pkey PRIMARY KEY (id);


--
-- Name: processo_parte_representante processo_parte_representante_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_parte_representante
    ADD CONSTRAINT processo_parte_representante_pkey PRIMARY KEY (id);


--
-- Name: processo_partes processo_partes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_partes
    ADD CONSTRAINT processo_partes_pkey PRIMARY KEY (id);


--
-- Name: processo_prioridades processo_prioridades_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_prioridades
    ADD CONSTRAINT processo_prioridades_pkey PRIMARY KEY (id);


--
-- Name: processos processos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processos
    ADD CONSTRAINT processos_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: tribunais tribunais_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tribunais
    ADD CONSTRAINT tribunais_pkey PRIMARY KEY (id);


--
-- Name: tribunais tribunais_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tribunais
    ADD CONSTRAINT tribunais_uuid_unique UNIQUE (uuid);


--
-- Name: processo_movimentos uq_processo_movimentos_processo_identificador; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_movimentos
    ADD CONSTRAINT uq_processo_movimentos_processo_identificador UNIQUE (processo_id, identificador_movimento);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: pulse_aggregates pulse_aggregates_bucket_period_type_aggregate_key_hash_unique; Type: CONSTRAINT; Schema: pulse; Owner: -
--

ALTER TABLE ONLY pulse.pulse_aggregates
    ADD CONSTRAINT pulse_aggregates_bucket_period_type_aggregate_key_hash_unique UNIQUE (bucket, period, type, aggregate, key_hash);


--
-- Name: pulse_aggregates pulse_aggregates_pkey; Type: CONSTRAINT; Schema: pulse; Owner: -
--

ALTER TABLE ONLY pulse.pulse_aggregates
    ADD CONSTRAINT pulse_aggregates_pkey PRIMARY KEY (id);


--
-- Name: pulse_entries pulse_entries_pkey; Type: CONSTRAINT; Schema: pulse; Owner: -
--

ALTER TABLE ONLY pulse.pulse_entries
    ADD CONSTRAINT pulse_entries_pkey PRIMARY KEY (id);


--
-- Name: pulse_values pulse_values_pkey; Type: CONSTRAINT; Schema: pulse; Owner: -
--

ALTER TABLE ONLY pulse.pulse_values
    ADD CONSTRAINT pulse_values_pkey PRIMARY KEY (id);


--
-- Name: pulse_values pulse_values_type_key_hash_unique; Type: CONSTRAINT; Schema: pulse; Owner: -
--

ALTER TABLE ONLY pulse.pulse_values
    ADD CONSTRAINT pulse_values_type_key_hash_unique UNIQUE (type, key_hash);


--
-- Name: documentos_model_type_model_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX documentos_model_type_model_id_index ON public.documentos USING btree (model_type, model_id);


--
-- Name: idx_processo_documentos_data_hora; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_processo_documentos_data_hora ON public.processo_documentos USING btree (data_hora);


--
-- Name: idx_processo_documentos_id_documento; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_processo_documentos_id_documento ON public.processo_documentos USING btree (id_documento);


--
-- Name: idx_processo_documentos_mimetype; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_processo_documentos_mimetype ON public.processo_documentos USING btree (mimetype);


--
-- Name: idx_processo_documentos_processo_id_documento; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_processo_documentos_processo_id_documento ON public.processo_documentos USING btree (processo_id, id_documento);


--
-- Name: idx_processo_documentos_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_processo_documentos_status ON public.processo_documentos USING btree (status);


--
-- Name: idx_processo_documentos_status_mimetype; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_processo_documentos_status_mimetype ON public.processo_documentos USING btree (status, mimetype);


--
-- Name: idx_processo_movimentos_codigo_nacional; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_processo_movimentos_codigo_nacional ON public.processo_movimentos USING btree (codigo_nacional);


--
-- Name: idx_processo_movimentos_data_hora; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_processo_movimentos_data_hora ON public.processo_movimentos USING btree (data_hora);


--
-- Name: idx_processo_movimentos_identificador; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_processo_movimentos_identificador ON public.processo_movimentos USING btree (identificador_movimento);


--
-- Name: idx_processo_movimentos_processo_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_processo_movimentos_processo_id ON public.processo_movimentos USING btree (processo_id);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: processo_documentos_file_size_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX processo_documentos_file_size_index ON public.processo_documentos USING btree (file_size);


--
-- Name: processo_exportacoes_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX processo_exportacoes_status_index ON public.processo_exportacoes USING btree (status);


--
-- Name: processo_exportacoes_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX processo_exportacoes_user_id_created_at_index ON public.processo_exportacoes USING btree (user_id, created_at);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: pulse_aggregates_period_bucket_index; Type: INDEX; Schema: pulse; Owner: -
--

CREATE INDEX pulse_aggregates_period_bucket_index ON pulse.pulse_aggregates USING btree (period, bucket);


--
-- Name: pulse_aggregates_period_type_aggregate_bucket_index; Type: INDEX; Schema: pulse; Owner: -
--

CREATE INDEX pulse_aggregates_period_type_aggregate_bucket_index ON pulse.pulse_aggregates USING btree (period, type, aggregate, bucket);


--
-- Name: pulse_aggregates_type_index; Type: INDEX; Schema: pulse; Owner: -
--

CREATE INDEX pulse_aggregates_type_index ON pulse.pulse_aggregates USING btree (type);


--
-- Name: pulse_entries_key_hash_index; Type: INDEX; Schema: pulse; Owner: -
--

CREATE INDEX pulse_entries_key_hash_index ON pulse.pulse_entries USING btree (key_hash);


--
-- Name: pulse_entries_timestamp_index; Type: INDEX; Schema: pulse; Owner: -
--

CREATE INDEX pulse_entries_timestamp_index ON pulse.pulse_entries USING btree ("timestamp");


--
-- Name: pulse_entries_timestamp_type_key_hash_value_index; Type: INDEX; Schema: pulse; Owner: -
--

CREATE INDEX pulse_entries_timestamp_type_key_hash_value_index ON pulse.pulse_entries USING btree ("timestamp", type, key_hash, value);


--
-- Name: pulse_entries_type_index; Type: INDEX; Schema: pulse; Owner: -
--

CREATE INDEX pulse_entries_type_index ON pulse.pulse_entries USING btree (type);


--
-- Name: pulse_values_timestamp_index; Type: INDEX; Schema: pulse; Owner: -
--

CREATE INDEX pulse_values_timestamp_index ON pulse.pulse_values USING btree ("timestamp");


--
-- Name: pulse_values_type_index; Type: INDEX; Schema: pulse; Owner: -
--

CREATE INDEX pulse_values_type_index ON pulse.pulse_values USING btree (type);


--
-- Name: notificacoes notificacoes_processo_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notificacoes
    ADD CONSTRAINT notificacoes_processo_id_foreign FOREIGN KEY (processo_id) REFERENCES public.processos(id);


--
-- Name: processo_documentos processo_documentos_processo_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_documentos
    ADD CONSTRAINT processo_documentos_processo_id_foreign FOREIGN KEY (processo_id) REFERENCES public.processos(id) ON DELETE CASCADE;


--
-- Name: processo_movimentos processo_movimentos_processo_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_movimentos
    ADD CONSTRAINT processo_movimentos_processo_id_foreign FOREIGN KEY (processo_id) REFERENCES public.processos(id) ON DELETE CASCADE;


--
-- Name: processo_parte_representante processo_parte_representante_parte_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_parte_representante
    ADD CONSTRAINT processo_parte_representante_parte_id_foreign FOREIGN KEY (parte_id) REFERENCES public.processo_partes(id) ON DELETE CASCADE;


--
-- Name: processo_parte_representante processo_parte_representante_processo_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_parte_representante
    ADD CONSTRAINT processo_parte_representante_processo_id_foreign FOREIGN KEY (processo_id) REFERENCES public.processos(id) ON DELETE CASCADE;


--
-- Name: processo_prioridades processo_prioridades_processo_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.processo_prioridades
    ADD CONSTRAINT processo_prioridades_processo_id_foreign FOREIGN KEY (processo_id) REFERENCES public.processos(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--


--
-- PostgreSQL database dump
--


-- Dumped from database version 15.14 (Debian 15.14-1.pgdg12+1)
-- Dumped by pg_dump version 15.14 (Debian 15.14-1.pgdg12+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
5	0001_01_01_000000_create_users_table	1
6	0001_01_01_000001_create_cache_table	1
7	0001_01_01_000002_create_jobs_table	1
8	2024_11_28_010302_create_processo_prioridades_table	2
9	2024_11_28_011235_add_nome_processo_assuntos_table	2
43	2024_11_28_165052_create_representante_processual_table	3
45	2024_11_28_215703_create_processo_movimentos_table	4
50	2024_11_28_222314_create_processo_documentos_table	5
51	2024_12_09_224602_add_uuid_to_tribunais_table	6
52	2024_12_09_224944_update_trinais_uuid_table	7
53	2024_12_15_200851_add_orgao_julgador_processos_table	8
54	2024_12_15_214800_create_personal_access_tokens_table	9
55	2025_01_06_200450_add_fields_processo_documentos_table	10
56	2025_01_15_150632_change_fields_processo_movimentos_table	10
57	2025_02_27_113705_add_fields_processo_documentos_table	10
58	2025_07_30_130412_change_processo_parte_representante_column	11
59	2025_08_06_151017_alter_valor_causa_processos_table	12
60	2025_08_06_151345_alter_valor_causa_processos_table	13
63	2025_08_18_185742_create_pulse_tables	14
64	2025_08_19_161148_add_indexes_processo_documentos_table	14
65	2025_08_19_161650_add_indexes_processo_movimentos_table	14
66	2025_08_27_150800_allow_null_codigo_complemento_processo_movimentos	15
68	2025_09_05_125942_create_pulse_tables	16
75	2025_09_08_144043_create_pulse_tables	17
76	2025_11_13_141837_add_fields_processo_documentos_table	17
77	2025_11_13_144137_add_fields_processos_table	17
78	2025_12_04_190000_add_file_size_to_processo_documentos_table	18
79	2025_12_17_150000_alter_knowledge_base_sequence_job_to_bigint_in_processos_table	19
81	2025_12_23_151123_add_ocr_concluido_data_to_processo_documentos_table	20
82	2026_01_07_121744_make_hash_nullable_in_processo_documentos_table	20
83	2026_01_19_122429_add_field_processos_table	21
84	2026_03_04_140305_add_ocr_enviado_fila_to_processo_documentos_table	21
85	2026_03_05_020106_add_usuario_juntada_arquivo_to_processo_documentos_table	21
86	2026_03_05_030000_create_notificacoes_table	21
87	2026_03_06_010000_add_data_juntada_to_processo_documentos_table	21
88	2026_03_11_100000_enable_pgvector_extension	22
89	2026_03_11_100001_create_document_chunks_table	22
90	2026_03_11_100002_add_embedding_status_to_processos_table	22
91	2026_04_17_000000_add_unique_processo_movimentos_processo_identificador	23
92	2026_04_23_120000_add_ocr_job_id_to_processo_documentos_table	23
93	2026_04_29_120000_create_processo_exportacoes_table	23
94	2026_05_07_000000_add_ocr_job_id_to_processo_documentos_table	23
95	2026_07_07_000000_drop_ocr_and_samia_columns	24
96	2026_05_22_180000_add_ocr_status_to_processos_table	25
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 96, true);


--
-- PostgreSQL database dump complete
--


