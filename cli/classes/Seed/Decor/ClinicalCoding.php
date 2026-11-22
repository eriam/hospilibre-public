<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed\Decor;

/**
 * Codage d'un décor : quel acte CCAM porte une intervention, quel diagnostic CIM-10 porte un séjour.
 *
 * SÉPARÉ DU CATALOGUE, et pas par goût du découpage : le catalogue décrit une TOPOLOGIE de soins — des
 * unités, qui opère, qui admet qui — là où le codage décrit une NOMENCLATURE, qui est nationale. Un
 * décor étranger garde le même catalogue et n'a pas de CCAM ; c'est pourquoi `SeedDecor::coding()` peut
 * rendre null.
 *
 * CE QUE CETTE INTERFACE REFUSE DE FAIRE, et c'est le cœur de l'affaire : TIRER UN CODE AU HASARD DANS
 * LE DICTIONNAIRE. La CCAM compte 8 600 actes actifs et la CIM-10 plus de 20 000 codes ; en tirer un au
 * sort donnerait une orthopédie qui pose des prothèses pour des pneumopathies. C'est exactement
 * l'incohérence de libellés corrigée au §12 du design, sous une autre forme. Le codage est donc une
 * CORRESPONDANCE DÉCLARÉE, que le dictionnaire sert à valider et non à alimenter.
 *
 * DEUX FORMES À NE PAS CONFONDRE :
 *
 * - un code CCAM s'écrit en MAJUSCULES, sept caractères : `NEKA020`. `CCodable` le met de toute façon
 *   en majuscules, et ne valide que sa forme — aucun dictionnaire n'est requis pour l'écrire ;
 * - un code CIM-10 s'écrit SANS LE POINT : `J189`, et non `J18.9`. C'est la colonne `abbrev` du
 *   dictionnaire, la seule que `CCodeCIM10` interroge, et elle vaut pour les versions OMS et ATIH.
 *   Contrairement à la CCAM, `CSejour::check()` exige que le code EXISTE : sans dictionnaire monté,
 *   l'écriture d'un DP provoque une erreur fatale.
 */
interface ClinicalCoding
{
    /**
     * Code CCAM de l'acte désigné par ce libellé, ou null si le décor ne le code pas.
     *
     * La clé est le libellé parce que c'est lui que le catalogue déclare et que l'intervention porte.
     * Un libellé sans code est une LACUNE du décor, pas une erreur : l'intervention est écrite sans
     * cotation, et le rapport la compte.
     */
    public function ccamFor(string $procedure): ?string;

    /**
     * Diagnostics d'admission possibles dans cette unité, dans la forme `abbrev`.
     *
     * @return string[] Vide quand l'unité n'est pas codée.
     */
    public function diagnosesFor(string $speciality): array;

    /**
     * Cet acte peut-il se faire en hospitalisation de jour ?
     *
     * POURQUOI LE CODAGE RÉPOND À CETTE QUESTION, et pas le catalogue : c'est l'acte qui décide, pas la
     * spécialité. Une chirurgie viscérale fait de l'ambulatoire — hémorroïdes, hernie — et de
     * l'hospitalisation complète — gastrectomie, colectomie — avec la même équipe et le même bloc.
     *
     * Le besoin est apparu en relisant les données CODÉES : 26 interventions sur 47 tombaient sur un
     * séjour ambulatoire, dont des gastrectomies partielles et des colectomies droites. Le type de
     * séjour est décidé par le mix clinique avant que la spécialité de l'acte ne soit connue, donc rien
     * ne les reliait. Le défaut existait avant le codage ; c'est le code CCAM qui l'a rendu lisible.
     */
    public function isAmbulatory(string $procedure): bool;

    /**
     * Diagnostics qui MOTIVENT cet acte.
     *
     * Prioritaires sur ceux de l'unité, et c'est toute la raison d'être de la méthode : un séjour
     * d'orthopédie peut légitimement entrer pour une gonarthrose ou pour une fracture du col, mais
     * celui qu'on opère d'une prothèse de hanche entre pour une coxarthrose. Sans ce niveau, le
     * diagnostic et l'acte seraient tirés indépendamment et se contrediraient une fois sur deux.
     *
     * @return string[] Vide quand l'acte n'a pas de diagnostic propre : ceux de l'unité s'appliquent.
     */
    public function diagnosesForProcedure(string $procedure): array;
}
