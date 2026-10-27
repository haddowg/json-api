# Changelog

## [1.19.0](https://github.com/haddowg/json-api/compare/v1.18.0...v1.19.0) (2026-10-27)


### Features

* declare a conditional action link on the resource ([#258](https://github.com/haddowg/json-api/issues/258)) ([26bb106](https://github.com/haddowg/json-api/commit/26bb106d68113312989f1a784dc4d69352eed636))

## [1.18.0](https://github.com/haddowg/json-api/compare/v1.17.4...v1.18.0) (2026-10-27)


### Features

* let a custom action answer with a URL ([#256](https://github.com/haddowg/json-api/issues/256)) ([1b79ec2](https://github.com/haddowg/json-api/commit/1b79ec2f9ca5bd59a874731d853ea3cfc4dc368c))

## [1.17.4](https://github.com/haddowg/json-api/compare/v1.17.3...v1.17.4) (2026-10-27)


### Bug Fixes

* reject links and relationships nested in attribute values ([#254](https://github.com/haddowg/json-api/issues/254)) ([11debb8](https://github.com/haddowg/json-api/commit/11debb846e24db27d63f9c411c8babbd1a849a09))

## [1.17.3](https://github.com/haddowg/json-api/compare/v1.17.2...v1.17.3) (2026-10-27)


### Bug Fixes

* reject relationships and links members inside attribute values ([#252](https://github.com/haddowg/json-api/issues/252)) ([4968c60](https://github.com/haddowg/json-api/commit/4968c6094501022d7bae1d32190fc1ee3c495579))

## [1.17.2](https://github.com/haddowg/json-api/compare/v1.17.1...v1.17.2) (2026-10-27)


### Bug Fixes

* reject attributes and relationships named type or id ([#250](https://github.com/haddowg/json-api/issues/250)) ([bd0f9c7](https://github.com/haddowg/json-api/commit/bd0f9c737ac50f96365ca7f4f986495f5544368f))

## [1.17.1](https://github.com/haddowg/json-api/compare/v1.17.0...v1.17.1) (2026-10-27)


### Bug Fixes

* keep other security alternatives beside the scope scheme ([#248](https://github.com/haddowg/json-api/issues/248)) ([dcf8b1b](https://github.com/haddowg/json-api/commit/dcf8b1bab87a11a1f62f46a882613d19c67867db))

## [1.17.0](https://github.com/haddowg/json-api/compare/v1.16.0...v1.17.0) (2026-10-27)


### Features

* add an operation catalogue for listing what a caller can use ([#246](https://github.com/haddowg/json-api/issues/246)) ([1e171d4](https://github.com/haddowg/json-api/commit/1e171d401d02f22083ec2d07c06c42ef9ca4aff7))
* enforce OAuth scopes per operation ([#243](https://github.com/haddowg/json-api/issues/243)) ([93209a6](https://github.com/haddowg/json-api/commit/93209a6ffc068b619334d2f7221f28cc71eece43))
* project a polymorphic relation as a union whatever its type count ([#247](https://github.com/haddowg/json-api/issues/247)) ([4b299e0](https://github.com/haddowg/json-api/commit/4b299e0ab8d9eb7523e35b1e0667bcfb92187db1))
* project operation scopes into the OpenAPI document ([#245](https://github.com/haddowg/json-api/issues/245)) ([e93a2a2](https://github.com/haddowg/json-api/commit/e93a2a2f170ed5ee8517ce4daaef565a0ee449e7))

## [1.16.0](https://github.com/haddowg/json-api/compare/v1.15.2...v1.16.0) (2026-10-26)


### Features

* give actions unique operation ids and declared safety ([#240](https://github.com/haddowg/json-api/issues/240)) ([9e41d3e](https://github.com/haddowg/json-api/commit/9e41d3e2cf1b63b15cbab70da51ed6e330bb7b51))

## [1.15.2](https://github.com/haddowg/json-api/compare/v1.15.1...v1.15.2) (2026-10-26)


### Bug Fixes

* join a relative link href to the base URI with a slash ([#238](https://github.com/haddowg/json-api/issues/238)) ([df3b16d](https://github.com/haddowg/json-api/commit/df3b16d4a89eb588fc1fa7f1b1e530fb75486f76))

## [1.15.1](https://github.com/haddowg/json-api/compare/v1.15.0...v1.15.1) (2026-10-26)


### Bug Fixes

* let spec validation accept named resource links beside self ([#236](https://github.com/haddowg/json-api/issues/236)) ([633b3c2](https://github.com/haddowg/json-api/commit/633b3c28d52f3b7a49794fa22b61fc910574f247))

## [1.15.0](https://github.com/haddowg/json-api/compare/v1.14.0...v1.15.0) (2026-10-26)


### Features

* let resources declare named links the OpenAPI document describes ([#234](https://github.com/haddowg/json-api/issues/234)) ([2b57499](https://github.com/haddowg/json-api/commit/2b57499294bb93c2c3b31cc57dad81a3801b25e4))

## [1.14.0](https://github.com/haddowg/json-api/compare/v1.13.1...v1.14.0) (2026-10-26)


### Features

* let examples show always-counted pages and declared linkage ids ([#231](https://github.com/haddowg/json-api/issues/231)) ([1dae7fd](https://github.com/haddowg/json-api/commit/1dae7fd99c8806f0c26264d32f38a9146be31962))

## [1.13.1](https://github.com/haddowg/json-api/compare/v1.13.0...v1.13.1) (2026-10-26)


### Bug Fixes

* build computed composite examples and give every error code a detail ([#230](https://github.com/haddowg/json-api/issues/230)) ([43104f6](https://github.com/haddowg/json-api/commit/43104f65768db49c1570176e2b13792f3c57441b))

## [1.13.0](https://github.com/haddowg/json-api/compare/v1.12.1...v1.13.0) (2026-10-26)


### Features

* generate operation examples from declared field examples ([#227](https://github.com/haddowg/json-api/issues/227)) ([f9730d6](https://github.com/haddowg/json-api/commit/f9730d6369f1d0939d3046a41655c08f3424647a))


### Bug Fixes

* report missing examples only for parts an override leaves generated ([#229](https://github.com/haddowg/json-api/issues/229)) ([30b12a8](https://github.com/haddowg/json-api/commit/30b12a860acd5dd69eb609ce083b906150359c0d))

## [1.12.1](https://github.com/haddowg/json-api/compare/v1.12.0...v1.12.1) (2026-10-26)


### Bug Fixes

* default a schemaless accepted body by its media-type family ([#225](https://github.com/haddowg/json-api/issues/225)) ([3d1978f](https://github.com/haddowg/json-api/commit/3d1978f75d8d0e6ccd1669335b3e4eea66f57528))

## [1.12.0](https://github.com/haddowg/json-api/compare/v1.11.0...v1.12.0) (2026-10-26)


### Features

* let an action declare the request bodies it accepts ([#223](https://github.com/haddowg/json-api/issues/223)) ([18ee4bb](https://github.com/haddowg/json-api/commit/18ee4bb0465e856d35b3dec1d398f908adab164c))

## [1.11.0](https://github.com/haddowg/json-api/compare/v1.10.0...v1.11.0) (2026-10-26)


### Features

* let a custom action answer with a raw response body ([#221](https://github.com/haddowg/json-api/issues/221)) ([e14e969](https://github.com/haddowg/json-api/commit/e14e969bdb4a0928bf03d6c56a89caa12bad0d74))
* let a server reject read-only attributes in write bodies ([#218](https://github.com/haddowg/json-api/issues/218)) ([bd5478f](https://github.com/haddowg/json-api/commit/bd5478f2791ebbc4bdc3b376cf1f301a9a877cf5))


### Bug Fixes

* apply the field cast to a computed value ([#222](https://github.com/haddowg/json-api/issues/222)) ([5b01335](https://github.com/haddowg/json-api/commit/5b01335a6caf42e59ab145cb48147ac0b29c5a76))
* exempt a bodyless preflighted write from the content type rule ([#219](https://github.com/haddowg/json-api/issues/219)) ([33f6a2c](https://github.com/haddowg/json-api/commit/33f6a2c8151599ca07f050d549006b8af4246fe6))
* render a Stringable id as its string ([#220](https://github.com/haddowg/json-api/issues/220)) ([646b871](https://github.com/haddowg/json-api/commit/646b871ab247b5f8894d24cbef776df4fe41b5ef))
* require the JSON:API content type on writes ([#217](https://github.com/haddowg/json-api/issues/217)) ([5dfb2f1](https://github.com/haddowg/json-api/commit/5dfb2f17448962ac9e110ed2179e536ba3c693f3))
* run a computed Obj's declared children over its value ([#215](https://github.com/haddowg/json-api/issues/215)) ([96b8d33](https://github.com/haddowg/json-api/commit/96b8d33f1c7b4778dfe78f7d9c80c632efbf0dc4))

## [1.10.0](https://github.com/haddowg/json-api/compare/v1.9.0...v1.10.0) (2026-10-25)


### Features

* render a polymorphic to-many's members from the out-of-band linkage seam ([#213](https://github.com/haddowg/json-api/issues/213)) ([3bf0298](https://github.com/haddowg/json-api/commit/3bf0298aa102661575c6a817bdfa86ec7fa6898c))

## [1.9.0](https://github.com/haddowg/json-api/compare/v1.8.1...v1.9.0) (2026-10-24)


### Features

* add a read gate for a relation hidden from the caller ([#210](https://github.com/haddowg/json-api/issues/210)) ([80c8e9f](https://github.com/haddowg/json-api/commit/80c8e9f6997ce098b2e39964a0f26121930718fe))
* let a host authorize rendered relation data and flattened writes ([#212](https://github.com/haddowg/json-api/issues/212)) ([bbf979a](https://github.com/haddowg/json-api/commit/bbf979aac8a251cac5360aa31fe4a86532f24448))


### Bug Fixes

* refuse relationship mutations on a read-only relation ([#209](https://github.com/haddowg/json-api/issues/209)) ([4fda0c4](https://github.com/haddowg/json-api/commit/4fda0c46af77b5534c061b53cd62cd7528f4ef75))

## [1.8.1](https://github.com/haddowg/json-api/compare/v1.8.0...v1.8.1) (2026-10-24)


### Bug Fixes

* **openapi:** show a real resource link in the LinkObject example ([14eb462](https://github.com/haddowg/json-api/commit/14eb4623eea51b4c6c3b45daf0a2a7b390aa2ed6))

## [1.8.0](https://github.com/haddowg/json-api/compare/v1.7.0...v1.8.0) (2026-10-24)


### Features

* **openapi:** let a type declare the singular noun generated prose uses ([5d0af7d](https://github.com/haddowg/json-api/commit/5d0af7d4314a5b4710d10bff103d70b343a76a82))


### Bug Fixes

* **openapi:** give each endpoint document its own links with whole-object examples ([5d0af7d](https://github.com/haddowg/json-api/commit/5d0af7d4314a5b4710d10bff103d70b343a76a82))
* **openapi:** give the open Meta and Links components an example ([#206](https://github.com/haddowg/json-api/issues/206)) ([89ec950](https://github.com/haddowg/json-api/commit/89ec950d3551bc7918ef9da943f3b931dde8def7))
* **openapi:** name one resource in the singular and stop restating the schema in prose ([5d0af7d](https://github.com/haddowg/json-api/commit/5d0af7d4314a5b4710d10bff103d70b343a76a82))
* **openapi:** show link and enum examples in tools that ignore siblings of a ref ([5d0af7d](https://github.com/haddowg/json-api/commit/5d0af7d4314a5b4710d10bff103d70b343a76a82))

## [1.7.0](https://github.com/haddowg/json-api/compare/v1.6.0...v1.7.0) (2026-10-24)


### Features

* **openapi:** mark a write-only relation writeOnly in the write bodies ([aea9983](https://github.com/haddowg/json-api/commit/aea9983459a9d717a08bc47a760392c9eaffc303))
* **openapi:** split a pivot's meta.pivot into response and request schemas ([aea9983](https://github.com/haddowg/json-api/commit/aea9983459a9d717a08bc47a760392c9eaffc303))
* resolve a pivot member's readable fields per request ([#203](https://github.com/haddowg/json-api/issues/203)) ([3f5e0ff](https://github.com/haddowg/json-api/commit/3f5e0ffd1609c3a75c00a56a5ab7662ae8143e0f))


### Bug Fixes

* **openapi:** key a relation's paths by its URI field name so links resolve ([aea9983](https://github.com/haddowg/json-api/commit/aea9983459a9d717a08bc47a760392c9eaffc303))

## [1.6.0](https://github.com/haddowg/json-api/compare/v1.5.1...v1.6.0) (2026-10-24)


### Features

* describe atomic results by the atomic endpoint's operation ([af85bbe](https://github.com/haddowg/json-api/commit/af85bbe3c0ebb960612da19abda66cb15fee01cb))
* expose the operations map SpecDescribedByResolver is built from ([af85bbe](https://github.com/haddowg/json-api/commit/af85bbe3c0ebb960612da19abda66cb15fee01cb))
* **openapi:** add the serve-time helpers adapters share ([#197](https://github.com/haddowg/json-api/issues/197)) ([5015d4f](https://github.com/haddowg/json-api/commit/5015d4f52a9d20cab6150403c34f1a7cef436194))
* **openapi:** name the rejected class on a typed serve-artifact exception ([#199](https://github.com/haddowg/json-api/issues/199)) ([bb23a02](https://github.com/haddowg/json-api/commit/bb23a02b03abb0fa04539c489f076d2dfd7a27ac))
* render built links as link objects with an OpenAPI describedby ([#195](https://github.com/haddowg/json-api/issues/195)) ([2cb1324](https://github.com/haddowg/json-api/commit/2cb1324d432e83001aca74ea1c8f6813ca5b425f))


### Bug Fixes

* never reference a document component the projector doesn't define ([af85bbe](https://github.com/haddowg/json-api/commit/af85bbe3c0ebb960612da19abda66cb15fee01cb))

## [1.5.1](https://github.com/haddowg/json-api/compare/v1.5.0...v1.5.1) (2026-10-24)


### Bug Fixes

* apply a nested child's hidden and per-request visibility rules ([6a04259](https://github.com/haddowg/json-api/commit/6a042599d640d9682b115580213a5c1b45f4388d))
* list a nested object's always-rendered children as required on read ([6a04259](https://github.com/haddowg/json-api/commit/6a042599d640d9682b115580213a5c1b45f4388d))

## [1.5.0](https://github.com/haddowg/json-api/compare/v1.4.0...v1.5.0) (2026-10-23)


### Features

* complete withers on the OpenAPI value objects, preserving vendor extensions ([d18a587](https://github.com/haddowg/json-api/commit/d18a587f67f49c3f9ea7bbca93ada52dc29d5a17))
* project each paginator's defaults and page-size cap onto its page schema ([#190](https://github.com/haddowg/json-api/issues/190)) ([97c5748](https://github.com/haddowg/json-api/commit/97c57487f018126e0b80dcdc63147e9f9568a4d4))
* project every enforced constraint into the schema it holds for ([beb59e1](https://github.com/haddowg/json-api/commit/beb59e150ff6ed2b8add13badb4195b58bb7c328))


### Bug Fixes

* don't render a writeOnly() relation in a response ([d18a587](https://github.com/haddowg/json-api/commit/d18a587f67f49c3f9ea7bbca93ada52dc29d5a17))
* project a read attribute as nullable unless a value is guaranteed ([beb59e1](https://github.com/haddowg/json-api/commit/beb59e150ff6ed2b8add13badb4195b58bb7c328))

## [1.4.0](https://github.com/haddowg/json-api/compare/v1.3.0...v1.4.0) (2026-10-23)


### Features

* let an adapter supply a to-one relation's target out of band ([#187](https://github.com/haddowg/json-api/issues/187)) ([80aa5b8](https://github.com/haddowg/json-api/commit/80aa5b83bfcf74ed6eb78fd4a7c0501dbfddc291))
* vendor extensions on OpenAPI operations, path items, parameters and responses ([20bd8ec](https://github.com/haddowg/json-api/commit/20bd8ec6976c99a133f0da0229b6762f1717ae8a))


### Bug Fixes

* reject an unknown include path on an empty collection ([20bd8ec](https://github.com/haddowg/json-api/commit/20bd8ec6976c99a133f0da0229b6762f1717ae8a))

## [1.3.0](https://github.com/haddowg/json-api/compare/v1.2.1...v1.3.0) (2026-10-23)


### Features

* describe every core error code for API consumers ([6b7bc60](https://github.com/haddowg/json-api/commit/6b7bc60340a4ae89d8ce88eb6dd6da9e03646a71))
* describe what an error code means in its projected component ([6b7bc60](https://github.com/haddowg/json-api/commit/6b7bc60340a4ae89d8ce88eb6dd6da9e03646a71))


### Bug Fixes

* reject a JSON array sent for a map-shaped attribute ([#185](https://github.com/haddowg/json-api/issues/185)) ([75f7e16](https://github.com/haddowg/json-api/commit/75f7e168b572eec1f8fb5e4e1596d53605bf7c67))
* report a whole-value schema violation at the exact pointer prefix ([#183](https://github.com/haddowg/json-api/issues/183)) ([b3437a4](https://github.com/haddowg/json-api/commit/b3437a427534684db30dc810432a3db40a84bbcf))

## [1.2.1](https://github.com/haddowg/json-api/compare/v1.2.0...v1.2.1) (2026-10-22)


### Bug Fixes

* answer a scalar request body with 400 instead of a TypeError ([86ce1ef](https://github.com/haddowg/json-api/commit/86ce1ef56815cf81b53ccb777c859e8938b78568))
* render empty maps and empty object members as JSON objects ([86ce1ef](https://github.com/haddowg/json-api/commit/86ce1ef56815cf81b53ccb777c859e8938b78568))
* **validation:** validate documents decoded as objects so {} stays an object ([86ce1ef](https://github.com/haddowg/json-api/commit/86ce1ef56815cf81b53ccb777c859e8938b78568))

## [1.2.0](https://github.com/haddowg/json-api/compare/v1.1.1...v1.2.0) (2026-10-22)


### Features

* catalogue codes for authentication and authorization failures ([e8f785a](https://github.com/haddowg/json-api/commit/e8f785ad9ebcf6d33bac5d3c40ca3d1f369ead9d))
* convert date-time attributes to an output timezone ([#178](https://github.com/haddowg/json-api/issues/178)) ([1e8863f](https://github.com/haddowg/json-api/commit/1e8863f37a910b7236956c59dd7a8e410bd644ef))
* **openapi:** name each page schema's strategy and key roles ([#175](https://github.com/haddowg/json-api/issues/175)) ([5b43b1a](https://github.com/haddowg/json-api/commit/5b43b1a9722103d155bb12720f3901c89a7b8827))


### Bug Fixes

* build pagination links from the configured keys and keep a menu's selection ([#177](https://github.com/haddowg/json-api/issues/177)) ([fdbe02e](https://github.com/haddowg/json-api/commit/fdbe02e0648383e4b2dc6b88574fdd9d906b0f78))
* **openapi:** project a filter's example onto its query parameter ([e8f785a](https://github.com/haddowg/json-api/commit/e8f785ad9ebcf6d33bac5d3c40ca3d1f369ead9d))
* **validation:** accept any URI-reference as a link ([e8f785a](https://github.com/haddowg/json-api/commit/e8f785ad9ebcf6d33bac5d3c40ca3d1f369ead9d))

## [1.1.1](https://github.com/haddowg/json-api/compare/v1.1.0...v1.1.1) (2026-10-22)


### Bug Fixes

* link documents with the mount once when a server is routed under a prefix ([#173](https://github.com/haddowg/json-api/issues/173)) ([f3804a5](https://github.com/haddowg/json-api/commit/f3804a5e070c6e09715212a35d2a053e7cd92363))

## [1.1.0](https://github.com/haddowg/json-api/compare/v1.0.0...v1.1.0) (2026-10-21)


### Features

* **openapi:** assemble the error catalogue from contributed sources ([#168](https://github.com/haddowg/json-api/issues/168)) ([33f9a79](https://github.com/haddowg/json-api/commit/33f9a79d68a87c0fe93786b5a19092e047911b7b))
* **openapi:** catalogue error codes as named schema variants ([#162](https://github.com/haddowg/json-api/issues/162)) ([2fd4015](https://github.com/haddowg/json-api/commit/2fd40152d1ed79ea0f0043b3cc9bdd03c9be89a5))
* **openapi:** declare include and fields on the writes that honour them ([#161](https://github.com/haddowg/json-api/issues/161)) ([c3b0255](https://github.com/haddowg/json-api/commit/c3b0255877819e56d116f13ef6f811ad1a3b19c2))
* **openapi:** expose the type set a server's projection describes ([#158](https://github.com/haddowg/json-api/issues/158)) ([d57eca7](https://github.com/haddowg/json-api/commit/d57eca710bcf670399d9dc466bf0ead873d76b3d))
* **openapi:** floor an underivable filter value at string ([#169](https://github.com/haddowg/json-api/issues/169)) ([5d82857](https://github.com/haddowg/json-api/commit/5d82857e554af9996a1a9e148e0e8c5ad0d08abd))
* **openapi:** share error responses and narrow them per status ([#170](https://github.com/haddowg/json-api/issues/170)) ([adbd13d](https://github.com/haddowg/json-api/commit/adbd13ddd6f6a37530e2cbd0fbd7c2efed9d00c9))
* **openapi:** stamp a generator contract on every projected document ([#159](https://github.com/haddowg/json-api/issues/159)) ([7c24e9a](https://github.com/haddowg/json-api/commit/7c24e9ab862ceeebb5b08a21c85e5f48aa1b0f83))
* **openapi:** type a filter value from the field it targets ([#166](https://github.com/haddowg/json-api/issues/166)) ([432f26a](https://github.com/haddowg/json-api/commit/432f26a043e76175d5f981b7a6756b5345226ae4))


### Bug Fixes

* **openapi:** describe only what the server actually serves ([#163](https://github.com/haddowg/json-api/issues/163)) ([c579db8](https://github.com/haddowg/json-api/commit/c579db8b5d8a7d63be3ceb3fe5f9660e68fe99ab))
* **openapi:** emit a temporal format keyword only when the field writes RFC 3339 ([#160](https://github.com/haddowg/json-api/issues/160)) ([ad8990b](https://github.com/haddowg/json-api/commit/ad8990bbbfef848c08e2ee7e5cffac89b4a3f21e))
* **openapi:** release-scope the generator contract, drop x-error-context ([#165](https://github.com/haddowg/json-api/issues/165)) ([67fc1fd](https://github.com/haddowg/json-api/commit/67fc1fd73b8185ed18f0119956a3f971ca308cb4))


### Miscellaneous Chores

* add openapi and atomic-operations to composer keywords ([#156](https://github.com/haddowg/json-api/issues/156)) ([39208da](https://github.com/haddowg/json-api/commit/39208da3c71b54decd4e044ca8131cb0a01fbbbe))
* tag releases as v-prefixed versions for Packagist ([#154](https://github.com/haddowg/json-api/issues/154)) ([47de1fe](https://github.com/haddowg/json-api/commit/47de1fe54ec1950df14f6a1b18421248ffd532e9))

## 1.0.0 (2026-07-13)


### ⚠ BREAKING CHANGES

* `Relation::make()` returns a relation builder, not the value object; `RelationInterface` no longer declares `withoutPagination()` or `canEagerLoad()`; `AbstractField` / `AbstractRelation` are removed (extend `AbstractFieldBuilder` / `AbstractRelationBuilder`).
* `Filter::make()` returns a filter builder, not the value object; the fluent withers live on the builder. Code that reads a resource's or relation's filters must call `allFilters()` rather than the raw `filters()` list.
* `when()` / `onCreate()` / `onUpdate()` closures on these types now receive the builder (e.g. `\Closure(Integer $field)` -> `\Closure(IntegerBuilder $field)`); `AbstractAttribute` is removed (extend `AbstractFieldBuilder`).
* the `when()` / `onCreate()` / `onUpdate()` closures now receive the field builder, not the value object, so a closure type hint changes from e.g. `\Closure(Str $field)` to `\Closure(StrBuilder $field)`. Code that consumes a resource's fields must read them through `allFields()` rather than the raw `fields()` declaration list.
* render relationship endpoints as linkage-only (no compound included) ([#113](https://github.com/haddowg/json-api/issues/113))
* negotiate profiles via Accept only, not the query parameter ([#109](https://github.com/haddowg/json-api/issues/109))
* **openapi:** project the cursor paginator's real page[after]/page[before] params ([#105](https://github.com/haddowg/json-api/issues/105))
* **openapi:** declare a custom action's success-output mode ([#103](https://github.com/haddowg/json-api/issues/103))
* **openapi:** type the pivot linkage meta in the projection ([#101](https://github.com/haddowg/json-api/issues/101))
* **openapi:** derive auth statuses from effective security and support public operation overrides ([#99](https://github.com/haddowg/json-api/issues/99))
* **openapi:** align the projected document with the served runtime ([#98](https://github.com/haddowg/json-api/issues/98))
* generate and allow overriding a description on every OpenAPI element ([#89](https://github.com/haddowg/json-api/issues/89))
* advertise a type's id pattern on the OpenAPI {id} parameter ([#87](https://github.com/haddowg/json-api/issues/87))
* require the related resource type on every relationship ([#84](https://github.com/haddowg/json-api/issues/84))
* build absolute links from the request origin by default ([#82](https://github.com/haddowg/json-api/issues/82))
* align the public API surface for the 1.0 freeze ([#75](https://github.com/haddowg/json-api/issues/75))
* add a parent-aware identifier-meta hook to relations ([#74](https://github.com/haddowg/json-api/issues/74))
* reject unknown sparse-fieldset members under the strict gate ([#68](https://github.com/haddowg/json-api/issues/68))
* make field visibility, writability and relationship authz request-aware ([#67](https://github.com/haddowg/json-api/issues/67))
* FieldInterface gains getDescription(), hasExample() and getExample().
* opt-in counting and pagination as the single source of truth ([#61](https://github.com/haddowg/json-api/issues/61))
* make relationship linkage data lazy by default, per relation type ([#60](https://github.com/haddowg/json-api/issues/60))
* rename relationship linkage methods to data ([#59](https://github.com/haddowg/json-api/issues/59))
* gate ?withCount behind a Relationship Counts profile ([#58](https://github.com/haddowg/json-api/issues/58))
* type custom constraints, remove the Custom escape hatch ([#29](https://github.com/haddowg/json-api/issues/29))
* drop the Timezone date constraint ([#27](https://github.com/haddowg/json-api/issues/27))
* pagination window seam, composite sort execution, and filter-key error ([#22](https://github.com/haddowg/json-api/issues/22))
* name all public interfaces with the Interface suffix ([#15](https://github.com/haddowg/json-api/issues/15))
* ship the documentation set and refine the public serializer/resource API ([#12](https://github.com/haddowg/json-api/issues/12))

### Features

* a per-operation serving hook, relation-scoped querying, and writable pivot fields ([#44](https://github.com/haddowg/json-api/issues/44)) ([4a0e380](https://github.com/haddowg/json-api/commit/4a0e380d8f5646cebfa0a5ceeadae3fda56e27e7))
* add a client-selectable pagination strategy menu ([#143](https://github.com/haddowg/json-api/issues/143)) ([845be93](https://github.com/haddowg/json-api/commit/845be931a579eea656b228723006b0c6a6158eaf))
* add a first-class custom-action operation ([#62](https://github.com/haddowg/json-api/issues/62)) ([7856fec](https://github.com/haddowg/json-api/commit/7856fec4a2c486dc07223b7e6da0294ddbc382b4))
* add a parent-aware identifier-meta hook to relations ([#74](https://github.com/haddowg/json-api/issues/74)) ([a9060cc](https://github.com/haddowg/json-api/commit/a9060cc9070b863cbee173efdd1f58eac9cf7970))
* add a per-page shorthand for resource pagination ([#86](https://github.com/haddowg/json-api/issues/86)) ([faf0bba](https://github.com/haddowg/json-api/commit/faf0bbaa296d2de6f636a9335ed09c583b5c49fa))
* add a resource-object link contributor seam ([#88](https://github.com/haddowg/json-api/issues/88)) ([78e0050](https://github.com/haddowg/json-api/commit/78e0050e3247cef7adca313017286e0ae18b4d70))
* add a when() field builder for conditional constraints ([#28](https://github.com/haddowg/json-api/issues/28)) ([4e0f936](https://github.com/haddowg/json-api/commit/4e0f93673d832764120ae032c4e4209d37c4e99d))
* add a worked music-catalog example and a hasResourceFor presence check ([#41](https://github.com/haddowg/json-api/issues/41)) ([d57481b](https://github.com/haddowg/json-api/commit/d57481b00b674e8352d937a39193ab981295afeb))
* add an intent-named convenience filter library ([#65](https://github.com/haddowg/json-api/issues/65)) ([a4ebe64](https://github.com/haddowg/json-api/commit/a4ebe64e31a21b83929cad6158bd85ed2fa33630))
* add Atomic Operations extension support ([#80](https://github.com/haddowg/json-api/issues/80)) ([e4ec54b](https://github.com/haddowg/json-api/commit/e4ec54b9a9ced8f630f0e7ae7da7b26c7163f601))
* add WhereAll/WhereAny filter groups and Where::fixed() ([#142](https://github.com/haddowg/json-api/issues/142)) ([13cf9a1](https://github.com/haddowg/json-api/commit/13cf9a1ce8f61fa649435c753db77e8df8e8e89a))
* advertise a linkage page's profile via IdentifierResponse::withPage ([#137](https://github.com/haddowg/json-api/issues/137)) ([78c4a30](https://github.com/haddowg/json-api/commit/78c4a30633cf872a9954ba5d5743dd42e3b7335e))
* advertise a type's id pattern on the OpenAPI {id} parameter ([#87](https://github.com/haddowg/json-api/issues/87)) ([6cff535](https://github.com/haddowg/json-api/commit/6cff535590976935f2df8ade48f70ae71fae5ecc))
* advertise applied extensions in jsonapi.ext ([#114](https://github.com/haddowg/json-api/issues/114)) ([00ac4b2](https://github.com/haddowg/json-api/commit/00ac4b207a603d75918dc9ec5bb64d71fd78788c))
* advertise profiles activated by a nested render ([#144](https://github.com/haddowg/json-api/issues/144)) ([45295b9](https://github.com/haddowg/json-api/commit/45295b923d22dc176a533e3c8f68f278f9562f89))
* async response affordances (202 Accepted / 303 See Other) ([#125](https://github.com/haddowg/json-api/issues/125)) ([f9c26e9](https://github.com/haddowg/json-api/commit/f9c26e9d3317fd2a6d31f1ab398149df2ed7c242))
* authorize a relationship independently of its parent ([#100](https://github.com/haddowg/json-api/issues/100)) ([bdca949](https://github.com/haddowg/json-api/commit/bdca9496f18313ab0afdc6b8a2e9e6077b154844))
* build absolute links from the request origin by default ([#82](https://github.com/haddowg/json-api/issues/82)) ([d5d71f3](https://github.com/haddowg/json-api/commit/d5d71f35ffb6d3e5dba4af1b6beb590551912293))
* compose a resource from independent capabilities with relationships and polymorphism ([#39](https://github.com/haddowg/json-api/issues/39)) ([f92c9ea](https://github.com/haddowg/json-api/commit/f92c9ea3220aed1b5590e7b75edea271c3ef4f07))
* composition combinators Sequentially and AtLeastOneOf ([#30](https://github.com/haddowg/json-api/issues/30)) ([51af9af](https://github.com/haddowg/json-api/commit/51af9af2284203007d0c0c7cc66cbfc44448778f))
* constraints self-describe their JSON Schema via ProvidesJsonSchema ([#123](https://github.com/haddowg/json-api/issues/123)) ([11bdab8](https://github.com/haddowg/json-api/commit/11bdab8939f20ee81cb0949b9ab53860eeb56c88))
* core SchemaValueValidator for opis value validation ([#131](https://github.com/haddowg/json-api/issues/131)) ([f89aa55](https://github.com/haddowg/json-api/commit/f89aa550fe0fdd69c37c126d8e8719df8915415c))
* cross-field comparison constraint CompareField ([#31](https://github.com/haddowg/json-api/issues/31)) ([fc57f57](https://github.com/haddowg/json-api/commit/fc57f57349d758bf52b958e0948636a3e83da7b0))
* cursor pagination and constrained-existence relationship filtering ([#47](https://github.com/haddowg/json-api/issues/47)) ([9a8d1a3](https://github.com/haddowg/json-api/commit/9a8d1a30203de4e786b45ba6f2f38d7e7a573e76))
* custom resource-id encoding and an explicit id-sourcing model ([#43](https://github.com/haddowg/json-api/issues/43)) ([e1a1c54](https://github.com/haddowg/json-api/commit/e1a1c54319530f5a1dc88d9e00740601f9292054))
* default sort, a page-size cap, and compound-document include safeguards ([#42](https://github.com/haddowg/json-api/issues/42)) ([8e6d895](https://github.com/haddowg/json-api/commit/8e6d8950be1bd2c4eb2a473c1d0a8cef878e361f))
* document the Atomic Operations endpoint in the OpenAPI spec ([#81](https://github.com/haddowg/json-api/issues/81)) ([c819130](https://github.com/haddowg/json-api/commit/c81913095086d948e8d2a7838a5bc030823300cc))
* drop the Timezone date constraint ([#27](https://github.com/haddowg/json-api/issues/27)) ([4ef9872](https://github.com/haddowg/json-api/commit/4ef9872eb9dfb38813ef0ae5bd41bd103c307c02))
* execute relationship-existence filters in ArrayFilterHandler ([#37](https://github.com/haddowg/json-api/issues/37)) ([aafe51f](https://github.com/haddowg/json-api/commit/aafe51f651d7374fc0f28091a0319feb53df1971))
* expose related-value resolution for related endpoints ([#34](https://github.com/haddowg/json-api/issues/34)) ([a904182](https://github.com/haddowg/json-api/commit/a904182733268154f02ffdb9dcba793e7b1e2039))
* expose the field value cast as a public castWireValue API ([#134](https://github.com/haddowg/json-api/issues/134)) ([77e1880](https://github.com/haddowg/json-api/commit/77e188076e70b5c60b70e2c1a47458b3cdedf3db))
* **filter:** add overridable default values to value-carrying filters ([#23](https://github.com/haddowg/json-api/issues/23)) ([2803177](https://github.com/haddowg/json-api/commit/28031773c3d1ec1756ea4be364fd723b096fda95))
* filters describe their own OpenAPI query parameter via DescribesQueryParameter ([#124](https://github.com/haddowg/json-api/issues/124)) ([894d80c](https://github.com/haddowg/json-api/commit/894d80c0b49ebb199c978850d9eab051a7c6557f))
* fluent schema layer (resources, fields, constraints, filters, sorts) and the Server config root ([#11](https://github.com/haddowg/json-api/issues/11)) ([1ecfdf9](https://github.com/haddowg/json-api/commit/1ecfdf9d458c5ca5da39d2eadbd8bd284821e495))
* gate ?withCount behind a Relationship Counts profile ([#58](https://github.com/haddowg/json-api/issues/58)) ([8ff145c](https://github.com/haddowg/json-api/commit/8ff145c6e29ba728d717e787d98c10821963f9ce))
* generate and allow overriding a description on every OpenAPI element ([#89](https://github.com/haddowg/json-api/issues/89)) ([87163d0](https://github.com/haddowg/json-api/commit/87163d0778bf2cd457dae611b1bfc9e8a6f5e3cf))
* hoist the keyset cursor execution machinery into core ([#136](https://github.com/haddowg/json-api/issues/136)) ([f019a95](https://github.com/haddowg/json-api/commit/f019a9541c04e28d193cca4a3aceaf5fa0130503))
* JSON:API 1.1 server-side core (requests, serialization, responses, operations) ([#7](https://github.com/haddowg/json-api/issues/7)) ([747521c](https://github.com/haddowg/json-api/commit/747521cc0d5ac5004795889d1771b5ff1f708db5))
* let ArrayList declare a typed element, defaulting to string ([#120](https://github.com/haddowg/json-api/issues/120)) ([22f5b32](https://github.com/haddowg/json-api/commit/22f5b32f10d39c1311f85c7c801200924bf4e0e0))
* let custom filters and sorts run on the reference handlers ([#66](https://github.com/haddowg/json-api/issues/66)) ([1626d08](https://github.com/haddowg/json-api/commit/1626d089beffd185cf2395f6fe73f007206eed25))
* let UnsupportedFilter/UnsupportedSort carry a remediation hint ([#122](https://github.com/haddowg/json-api/issues/122)) ([729e6ed](https://github.com/haddowg/json-api/commit/729e6edfc7956048e0a0f00780bf1d35dfcb3f5c))
* load-aware relationship linkage via linkageOnlyWhenLoaded ([#33](https://github.com/haddowg/json-api/issues/33)) ([88c0537](https://github.com/haddowg/json-api/commit/88c0537cae9a3d04e82bff79303ce2c0d24b11b3))
* localizable, overridable error catalogue via a message resolver ([#141](https://github.com/haddowg/json-api/issues/141)) ([3feadd3](https://github.com/haddowg/json-api/commit/3feadd310cb330e4660b02453498af871b0327d7))
* make field visibility, writability and relationship authz request-aware ([#67](https://github.com/haddowg/json-api/issues/67)) ([bfa7afd](https://github.com/haddowg/json-api/commit/bfa7afd48b819d3405d5dd4d01368cd8dcbb73e9))
* make relationship linkage data lazy by default, per relation type ([#60](https://github.com/haddowg/json-api/issues/60)) ([f73e935](https://github.com/haddowg/json-api/commit/f73e93576dae1afa2c5cc8067894e241ba0bd5f3))
* make the OpenAPI projection registration-aware ([#145](https://github.com/haddowg/json-api/issues/145)) ([bf315a1](https://github.com/haddowg/json-api/commit/bf315a12a84271b4ede5184b5e77195cf8a850d6))
* make the top-level describedby link first-class ([#118](https://github.com/haddowg/json-api/issues/118)) ([3451318](https://github.com/haddowg/json-api/commit/34513181f42f859408968e72984f030a20dcc1d3))
* **middleware:** enforce request top-level members at the edge ([#18](https://github.com/haddowg/json-api/issues/18)) ([d7b1d9c](https://github.com/haddowg/json-api/commit/d7b1d9c803d8d7fa47ef9db6b5ef089edb1b7cbf))
* Obj typed-object field via a self-describing schema seam ([#128](https://github.com/haddowg/json-api/issues/128)) ([1b51c71](https://github.com/haddowg/json-api/commit/1b51c71765ab04f3f0d7980c02dd7fa12ec46eb6))
* OneOf discriminated-union field ([#129](https://github.com/haddowg/json-api/issues/129)) ([ed9de8d](https://github.com/haddowg/json-api/commit/ed9de8d8bb870836cb31f07f567c6d538eaca0b4))
* **openapi:** advertise a parameter's required profile via x-profile ([#102](https://github.com/haddowg/json-api/issues/102)) ([8f10107](https://github.com/haddowg/json-api/commit/8f10107edc939d80fae95bfb25cc24fdd9e75d2c))
* **openapi:** declare a custom action's success-output mode ([#103](https://github.com/haddowg/json-api/issues/103)) ([9a12037](https://github.com/haddowg/json-api/commit/9a12037a8e3a11d3bb5473297d78b22f1dd3d68b))
* **openapi:** derive auth statuses from effective security and support public operation overrides ([#99](https://github.com/haddowg/json-api/issues/99)) ([1291067](https://github.com/haddowg/json-api/commit/129106729df7c9506ef16efb3ef734d9183aed24))
* **openapi:** enumerate fieldset members and share links/meta components ([#115](https://github.com/haddowg/json-api/issues/115)) ([884d502](https://github.com/haddowg/json-api/commit/884d5022d7b6db1da75608816294507d82704b7b))
* **openapi:** give custom actions a generated summary/description default ([#108](https://github.com/haddowg/json-api/issues/108)) ([3954bc2](https://github.com/haddowg/json-api/commit/3954bc2cb3ed2860a1e2e82cef7242a750cabd9d))
* **openapi:** mark conditionally-present read attributes optional ([#119](https://github.com/haddowg/json-api/issues/119)) ([1bad0f1](https://github.com/haddowg/json-api/commit/1bad0f180e883e135ade2c784036bdf30ecd9c3e))
* **openapi:** type the pivot linkage meta in the projection ([#101](https://github.com/haddowg/json-api/issues/101)) ([6a80a3f](https://github.com/haddowg/json-api/commit/6a80a3fcdcc23c9e0ada014665222c291262eb38))
* **openapi:** type the write-request relationships per settable relation ([#112](https://github.com/haddowg/json-api/issues/112)) ([768e9ac](https://github.com/haddowg/json-api/commit/768e9ac7adc66c853b9cb021064aff1db180be9f))
* opt-in counting and pagination as the single source of truth ([#61](https://github.com/haddowg/json-api/issues/61)) ([b31ede7](https://github.com/haddowg/json-api/commit/b31ede744c59cbfb0eea2d6ba877d0f60ac51810))
* optional JSON Schema validation of request/response documents ([#10](https://github.com/haddowg/json-api/issues/10)) ([45af4c4](https://github.com/haddowg/json-api/commit/45af4c4bf67077ef2d05db50daa091c0a1784722))
* pagination window seam, composite sort execution, and filter-key error ([#22](https://github.com/haddowg/json-api/issues/22)) ([c5250d5](https://github.com/haddowg/json-api/commit/c5250d5ebd808fb2b1af49c130dea50c748e032a))
* per-operation OpenAPI response declarations ([#139](https://github.com/haddowg/json-api/issues/139)) ([973ad10](https://github.com/haddowg/json-api/commit/973ad1087c169fa0ea4b30eb295f793813d20331))
* profile infrastructure and a Paginator/Page rewrite ([#8](https://github.com/haddowg/json-api/issues/8)) ([f04dfc0](https://github.com/haddowg/json-api/commit/f04dfc0a993a6d9aea468bdd02cf7b1b7085b381))
* project OpenAPI 3.1 documents from JSON:API metadata ([#63](https://github.com/haddowg/json-api/issues/63)) ([d3691f3](https://github.com/haddowg/json-api/commit/d3691f369a0bfffda2e08f5c85cc988058fcd796))
* PSR-15 middleware suite for the JSON:API request lifecycle ([#9](https://github.com/haddowg/json-api/issues/9)) ([1c58569](https://github.com/haddowg/json-api/commit/1c58569282cf8a730ee1e54d15404b468a532a4a))
* reject unknown sparse-fieldset members under the strict gate ([#68](https://github.com/haddowg/json-api/issues/68)) ([7f71416](https://github.com/haddowg/json-api/commit/7f71416fb9a6ca88742b17f4a469532162552c62))
* relationship counts, the relationship-queries profile, and stricter query handling ([#46](https://github.com/haddowg/json-api/issues/46)) ([3aa606b](https://github.com/haddowg/json-api/commit/3aa606b71a59bf83dbc10c37f5aa176b0b79bec2))
* relationship-endpoint hydration with mutability gates ([#36](https://github.com/haddowg/json-api/issues/36)) ([abc4ee8](https://github.com/haddowg/json-api/commit/abc4ee8f15cc10e3d90bcf6ca390bf09db3d4260))
* render relationship self/related links by convention ([#32](https://github.com/haddowg/json-api/issues/32)) ([3d9896c](https://github.com/haddowg/json-api/commit/3d9896c9efc6a2c1d8b105f1fec237126a278d2d))
* require the related resource type on every relationship ([#84](https://github.com/haddowg/json-api/issues/84)) ([7bc3252](https://github.com/haddowg/json-api/commit/7bc3252a1b4f8bbc76e27c663581833e2d4ff977))
* resolve registry instances through an injectable container ([#20](https://github.com/haddowg/json-api/issues/20)) ([96a6d49](https://github.com/haddowg/json-api/commit/96a6d498b9a9470444ca1cce1336a0685cc4fc2a))
* **response:** add a meta getter and a merge wither to AbstractResponse ([#117](https://github.com/haddowg/json-api/issues/117)) ([8c6d6ea](https://github.com/haddowg/json-api/commit/8c6d6ea4fb7da6e253aca16209b922cb5355bf6e))
* self links by convention and declarable filter value constraints ([#45](https://github.com/haddowg/json-api/issues/45)) ([5b494f7](https://github.com/haddowg/json-api/commit/5b494f7416fde89bc99529e73019dc5fa470b1bf))
* serialize document top-level members in a canonical order ([#64](https://github.com/haddowg/json-api/issues/64)) ([0e08c85](https://github.com/haddowg/json-api/commit/0e08c85731570d273579e30dd36498f10fbcea38))
* **serializer:** expose TransformerTrait as a composable public trait ([#19](https://github.com/haddowg/json-api/issues/19)) ([e26cf75](https://github.com/haddowg/json-api/commit/e26cf757a1a1f698daacf1d172815c0be9ae8ee8))
* settable response status and a No Content response shape ([#26](https://github.com/haddowg/json-api/issues/26)) ([a808332](https://github.com/haddowg/json-api/commit/a8083325bf976b7ea6360d34c66080f69e131b4f))
* Shape composite-schema constraint ([#130](https://github.com/haddowg/json-api/issues/130)) ([dabee9e](https://github.com/haddowg/json-api/commit/dabee9e571f1120dbe2210571a5a52c1cd08e7f9))
* singular filters and the v1 public-surface hardening ([#40](https://github.com/haddowg/json-api/issues/40)) ([ec32e4b](https://github.com/haddowg/json-api/commit/ec32e4b17250758d508762cc491e979020e80f22))
* sparse-by-default fields (opt-in via fields[type]) ([#126](https://github.com/haddowg/json-api/issues/126)) ([93735d6](https://github.com/haddowg/json-api/commit/93735d6f8469e1a9210d05dec79e7e4bb2fef95c))
* split field builders from the readonly value objects they build ([#146](https://github.com/haddowg/json-api/issues/146)) ([8228497](https://github.com/haddowg/json-api/commit/82284979838e68c4fa2650b2ded85efd1f66ab39))
* type custom constraints, remove the Custom escape hatch ([#29](https://github.com/haddowg/json-api/issues/29)) ([5180582](https://github.com/haddowg/json-api/commit/5180582eacecced84f994c15688ce25ebbfd5622))


### Bug Fixes

* bind a to-one's serializer even when the related value is null ([#35](https://github.com/haddowg/json-api/issues/35)) ([e656ecc](https://github.com/haddowg/json-api/commit/e656ecc80633330b20f16439c370c0fbb840b646))
* error-document status reflects a uniform error set ([#25](https://github.com/haddowg/json-api/issues/25)) ([4306600](https://github.com/haddowg/json-api/commit/43066006cdcd8936e1ce63116d6e71acb8e21e89))
* negotiate profiles via Accept only, not the query parameter ([#109](https://github.com/haddowg/json-api/issues/109)) ([783148c](https://github.com/haddowg/json-api/commit/783148c9cb4075fb033b25261e19056c20940340))
* nullable Shape combinators must admit null in the projected schema ([#132](https://github.com/haddowg/json-api/issues/132)) ([4642f95](https://github.com/haddowg/json-api/commit/4642f95c1479afffb1bfcb3443b386f0b9825c31))
* **openapi:** advertise 401 on the atomic operation from its effective security ([#106](https://github.com/haddowg/json-api/issues/106)) ([2cc99e1](https://github.com/haddowg/json-api/commit/2cc99e12b5b86cf8e19e122893599b452d47da5d))
* **openapi:** align the generated schema with real responses ([#90](https://github.com/haddowg/json-api/issues/90)) ([bff8915](https://github.com/haddowg/json-api/commit/bff89159e0b6b663f01deaf2c25a79db8c28e4b4))
* **openapi:** align the projected document with the served runtime ([#98](https://github.com/haddowg/json-api/issues/98)) ([22541c5](https://github.com/haddowg/json-api/commit/22541c565dead1e30266ff658c00f1ddddde829e))
* **openapi:** correct the unknown-sort error title typo and message punctuation ([#104](https://github.com/haddowg/json-api/issues/104)) ([d10e11a](https://github.com/haddowg/json-api/commit/d10e11a84764bf8b9375c4058a30a469cd30b3fe))
* **openapi:** gate write components on the operation allow-list ([#111](https://github.com/haddowg/json-api/issues/111)) ([67b5ba3](https://github.com/haddowg/json-api/commit/67b5ba39c044c76e970ecd3f53a1febc0b6a5eb7))
* **openapi:** mark read-only pivot fields readOnly and required pivot fields required ([#107](https://github.com/haddowg/json-api/issues/107)) ([93be08a](https://github.com/haddowg/json-api/commit/93be08ac10e9b8027345c6e0ef0f670cb3abfe1a))
* **openapi:** model atomic id/lid exclusivity as a titled oneOf ([#92](https://github.com/haddowg/json-api/issues/92)) ([e7c0760](https://github.com/haddowg/json-api/commit/e7c07605e5dbcdaf0ac4c152a8eac685137e4d09))
* **openapi:** project filter, sort and page on the to-many relationship endpoint ([#96](https://github.com/haddowg/json-api/issues/96)) ([9c6b6a3](https://github.com/haddowg/json-api/commit/9c6b6a3f61a23d9034b4136e9f9e5888d1dda99d))
* **openapi:** project read/create/update attributes in their own context ([#94](https://github.com/haddowg/json-api/issues/94)) ([d438049](https://github.com/haddowg/json-api/commit/d438049d940f40a494964db2c3a3a05d60c557a0))
* **openapi:** project the cursor paginator's real page[after]/page[before] params ([#105](https://github.com/haddowg/json-api/issues/105)) ([0363915](https://github.com/haddowg/json-api/commit/03639158e749a41865ad0b53b148021404de64b9))
* **openapi:** project the merged filter/sort vocabulary on related/relationship endpoints ([#95](https://github.com/haddowg/json-api/issues/95)) ([5ecab3b](https://github.com/haddowg/json-api/commit/5ecab3b203af254488369cd535e3fb43af166d48))
* **openapi:** share one attributes component per shape and exclude atomic id+lid ([#91](https://github.com/haddowg/json-api/issues/91)) ([ebdd14c](https://github.com/haddowg/json-api/commit/ebdd14c605c7acc4cb102ff810b4c94f85c10b53))
* **openapi:** split atomic write into discrete add and update schemas ([#93](https://github.com/haddowg/json-api/issues/93)) ([3e935af](https://github.com/haddowg/json-api/commit/3e935aff9134b98a28e4ebb7938822e3298eb674))
* **openapi:** take no query parameters on a polymorphic to-many relationship endpoint ([#97](https://github.com/haddowg/json-api/issues/97)) ([d9a8403](https://github.com/haddowg/json-api/commit/d9a84034f9ba6ea6b131633fcb7025ee8f81e509))
* reject a local id (lid) outside an Atomic Operations batch ([#110](https://github.com/haddowg/json-api/issues/110)) ([fa67fbb](https://github.com/haddowg/json-api/commit/fa67fbb2c8244cea3ef43a560e7dc11758570e8a))
* render relationship endpoints as linkage-only (no compound included) ([#113](https://github.com/haddowg/json-api/issues/113)) ([64978ab](https://github.com/haddowg/json-api/commit/64978ab41c4bfab70980a623444db00dbb374d5a))
* **request:** invalidate parsed extensions cache when headers change ([#14](https://github.com/haddowg/json-api/issues/14)) ([bfe8a72](https://github.com/haddowg/json-api/commit/bfe8a728e3c5b2c6f61efc76ee38d3212c1757eb))
* witness-contract fixes from the Laravel integration ([#127](https://github.com/haddowg/json-api/issues/127)) ([d59cd71](https://github.com/haddowg/json-api/commit/d59cd71dcd39c30ecb972ae25dde89b08f50ed4e))


### Miscellaneous Chores

* bootstrap repository tooling and scaffolding ([#5](https://github.com/haddowg/json-api/issues/5)) ([fde36c6](https://github.com/haddowg/json-api/commit/fde36c6e86b64bd6de34d8b7c14031eb0721e627))
* build the docs with mkdocs --strict as a pre-commit hook ([#77](https://github.com/haddowg/json-api/issues/77)) ([0f621b2](https://github.com/haddowg/json-api/commit/0f621b215f574808c25aa97f20ef29e7d58ce085))
* exclude non-runtime files from the Composer dist archive ([#152](https://github.com/haddowg/json-api/issues/152)) ([c13e502](https://github.com/haddowg/json-api/commit/c13e502d2b7c8cd6230d6ffa9bfbc59240afc78f))
* raise PHPStan memory limit to 1G ([#17](https://github.com/haddowg/json-api/issues/17)) ([913fe64](https://github.com/haddowg/json-api/commit/913fe64b443a8210039f8d67a8e8adcae72c3c97))
* release 1.0.0 ([#153](https://github.com/haddowg/json-api/issues/153)) ([1e8de0c](https://github.com/haddowg/json-api/commit/1e8de0cf6230d775313492a856463f4411f8c7f1))
* remove planning artifacts and prepare docs for release ([#151](https://github.com/haddowg/json-api/issues/151)) ([d84e5f0](https://github.com/haddowg/json-api/commit/d84e5f0c6cb227f9ffc9e87aeea518c893924a60))
* untrack __pycache__ and gitignore it ([#135](https://github.com/haddowg/json-api/issues/135)) ([ad74c09](https://github.com/haddowg/json-api/commit/ad74c091a558acae0fc25d7d17b28d4cdd26f9f4))


### Code Refactoring

* align the public API surface for the 1.0 freeze ([#75](https://github.com/haddowg/json-api/issues/75)) ([76cfa7c](https://github.com/haddowg/json-api/commit/76cfa7ca22b2536e123bd8115e183b6e9a170cb0))
* name all public interfaces with the Interface suffix ([#15](https://github.com/haddowg/json-api/issues/15)) ([7475a76](https://github.com/haddowg/json-api/commit/7475a761f8498ce21d5f118918d49e53a6f76a17))
* rename relationship linkage methods to data ([#59](https://github.com/haddowg/json-api/issues/59)) ([177f353](https://github.com/haddowg/json-api/commit/177f35328a81999c5cae9594ad4a1c9169c82f7c))
* ship the documentation set and refine the public serializer/resource API ([#12](https://github.com/haddowg/json-api/issues/12)) ([0f271f3](https://github.com/haddowg/json-api/commit/0f271f315128b924e97fe54fe139f0ccc9b151c2))
* split filter builders from their readonly value objects ([#148](https://github.com/haddowg/json-api/issues/148)) ([b625122](https://github.com/haddowg/json-api/commit/b62512217e16a6282d573839a31398968d231e54))
* split relation builders from their readonly value objects ([#149](https://github.com/haddowg/json-api/issues/149)) ([0fe41c5](https://github.com/haddowg/json-api/commit/0fe41c54c345f33384d732993f732f5c2a62642d))
* split remaining attributes into builders and value objects ([#147](https://github.com/haddowg/json-api/issues/147)) ([03c541f](https://github.com/haddowg/json-api/commit/03c541f46f7c235bcc04cecb53dd290164dfc87e))
