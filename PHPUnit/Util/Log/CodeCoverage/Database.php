<?php
/**
 * PHPUnit
 *
 * Copyright (c) 2002-2007, Sebastian Bergmann <sb@sebastian-bergmann.de>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Sebastian Bergmann nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category   Testing
 * @package    PHPUnit
 * @author     Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @copyright  2002-2007 Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version    SVN: $Id$
 * @link       http://www.phpunit.de/
 * @since      File available since Release 3.1.4
 */

require_once 'PHPUnit/Framework.php';
require_once 'PHPUnit/Util/Metrics/Project.php';
require_once 'PHPUnit/Util/Class.php';
require_once 'PHPUnit/Util/CodeCoverage.php';
require_once 'PHPUnit/Util/Filesystem.php';
require_once 'PHPUnit/Util/Filter.php';

PHPUnit_Util_Filter::addFileToFilter(__FILE__, 'PHPUNIT');

/**
 * 
 *
 * @category   Testing
 * @package    PHPUnit
 * @author     Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @copyright  2002-2007 Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version    Release: @package_version@
 * @link       http://www.phpunit.de/
 * @since      Class available since Release 3.1.4
 */
class PHPUnit_Util_Log_CodeCoverage_Database
{
    /**
     * @var    PDO
     * @access protected
     */
    protected $dbh;

    /**
     * Constructor.
     *
     * @param  PDO $dbh
     * @throws PDOException
     * @access public
     */
    public function __construct(PDO $dbh)
    {
        $this->dbh = $dbh;
    }

    /**
     * Stores code coverage information.
     *
     * @param  PHPUnit_Framework_TestResult $result
     * @param  integer                      $runId
     * @param  integer                      $revision
     * @param  string                       $commonPath
     * @access public
     */
    public function storeCodeCoverage(PHPUnit_Framework_TestResult $result, $runId, $revision, $commonPath = '')
    {
        $codeCoverage   = $result->getCodeCoverageInformation(FALSE, TRUE);
        $summary        = PHPUnit_Util_CodeCoverage::getSummary($codeCoverage);
        $files          = array_keys($summary);
        $projectMetrics = new PHPUnit_Util_Metrics_Project($files, $summary);
        $storedClasses  = array();

        if (empty($commonPath)) {
            $commonPath = PHPUnit_Util_Filesystem::getCommonPath($files);
        }

        $this->dbh->beginTransaction();

        foreach ($files as $file) {
            $filename    = str_replace($commonPath, '', $file);
            $fileId      = FALSE;
            $fileMetrics = $projectMetrics->getFile($file);
            $lines       = $fileMetrics->getLines();
            $hash        = md5_file($file);

            $stmt = $this->dbh->prepare(
              'SELECT code_file_id
                 FROM code_file
                WHERE code_file_name = :filename
                  AND revision       = :revision;'
            );

            $stmt->bindParam(':filename', $filename, PDO::PARAM_STR);
            $stmt->bindParam(':revision', $revision, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt) {
                $fileId = (int)$stmt->fetchColumn();
            }

            unset($stmt);

            if ($fileId == 0) {
                $stmt = $this->dbh->prepare(
                  'INSERT INTO code_file
                               (code_file_name, code_file_md5, revision)
                         VALUES(:filename, :hash, :revision);'
                );

                $stmt->bindParam(':filename', $filename, PDO::PARAM_STR);
                $stmt->bindParam(':hash', $hash, PDO::PARAM_STR);
                $stmt->bindParam(':revision', $revision, PDO::PARAM_INT);
                $stmt->execute();

                $fileId  = $this->dbh->lastInsertId();

                $stmt = $this->dbh->prepare(
                  'INSERT INTO code_class
                               (code_file_id, code_class_name,
                                code_class_start_line, code_class_end_line)
                         VALUES(:fileId, :className, :startLine, :endLine);'
                );

                foreach ($fileMetrics->getClasses() as $classMetrics) {
                    $className = $classMetrics->getClass()->getName();
                    $startLine = $classMetrics->getClass()->getStartLine();
                    $endLine   = $classMetrics->getClass()->getEndLine();

                    $stmt->bindParam(':fileId', $fileId, PDO::PARAM_INT);
                    $stmt->bindParam(':className', $className, PDO::PARAM_STR);
                    $stmt->bindParam(':startLine', $startLine, PDO::PARAM_INT);
                    $stmt->bindParam(':endLine', $endLine, PDO::PARAM_INT);
                    $stmt->execute();

                    $classId                   = $this->dbh->lastInsertId();
                    $storedClasses[$className] = $classId;

                    $stmt2 = $this->dbh->prepare(
                      'INSERT INTO code_method
                                   (code_class_id, code_method_name,
                                    code_method_start_line, code_method_end_line)
                             VALUES(:classId, :methodName, :startLine, :endLine);'
                    );

                    foreach ($classMetrics->getMethods() as $methodMetrics) {
                        $methodName = $methodMetrics->getMethod()->getName();
                        $startLine  = $methodMetrics->getMethod()->getStartLine();
                        $endLine    = $methodMetrics->getMethod()->getEndLine();

                        $stmt2->bindParam(':classId', $classId, PDO::PARAM_INT);
                        $stmt2->bindParam(':methodName', $methodName, PDO::PARAM_STR);
                        $stmt2->bindParam(':startLine', $startLine, PDO::PARAM_INT);
                        $stmt2->bindParam(':endLine', $endLine, PDO::PARAM_INT);
                        $stmt2->execute();
                    }

                    unset($stmt2);
                }

                $stmt = $this->dbh->prepare(
                  'INSERT INTO code_line
                               (code_file_id, code_line_number, code_line,
                                code_line_covered)
                         VALUES(:fileId, :lineNumber, :line, :covered);'
                );

                $i = 1;

                foreach ($lines as $line) {
                    $covered = isset($summary[$file][$i]) ? 1 : 0;

                    $stmt->bindParam(':fileId', $fileId, PDO::PARAM_INT);
                    $stmt->bindParam(':lineNumber', $i, PDO::PARAM_INT);
                    $stmt->bindParam(':line', $line, PDO::PARAM_STR);
                    $stmt->bindParam(':covered', $covered, PDO::PARAM_INT);
                    $stmt->execute();

                    $i++;
                }
            }

            $stmt = $this->dbh->prepare(
              'INSERT INTO metrics_file
                           (run_id, code_file_id, metrics_file_coverage,
                           metrics_file_loc, metrics_file_cloc, metrics_file_ncloc,
                           metrics_file_loc_executable, metrics_file_loc_executed)
                     VALUES(:runId, :fileId, :coverage, :loc, :cloc, :ncloc,
                            :locExecutable, :locExecuted);'
            );

            $coverage      = $fileMetrics->getCoverage();
            $loc           = $fileMetrics->getLoc();
            $cloc          = $fileMetrics->getCloc();
            $ncloc         = $fileMetrics->getNcloc();
            $locExecutable = $fileMetrics->getLocExecutable();
            $locExecuted   = $fileMetrics->getLocExecuted();

            $stmt->bindParam(':runId', $runId, PDO::PARAM_INT);
            $stmt->bindParam(':fileId', $fileId, PDO::PARAM_INT);
            $stmt->bindParam(':coverage', $coverage);
            $stmt->bindParam(':loc', $loc, PDO::PARAM_INT);
            $stmt->bindParam(':cloc', $cloc, PDO::PARAM_INT);
            $stmt->bindParam(':ncloc', $ncloc, PDO::PARAM_INT);
            $stmt->bindParam(':locExecutable', $locExecutable, PDO::PARAM_INT);
            $stmt->bindParam(':locExecuted', $locExecuted, PDO::PARAM_INT);
            $stmt->execute();

            $stmtSelectFunctionId = $this->dbh->prepare(
              'SELECT code_function_id
                 FROM code_file, code_function
                WHERE code_function.code_file_id       = code_file.code_file_id
                  AND code_file.revision               = :revision
                  AND code_function.code_function_name = :functionName;'
            );

            $stmtInsertFunction = $this->dbh->prepare(
              'INSERT INTO metrics_function
                           (run_id, code_function_id, metrics_function_coverage,
                           metrics_function_loc, metrics_function_loc_executable, metrics_function_loc_executed,
                           metrics_function_ccn, metrics_function_crap)
                     VALUES(:runId, :functionId, :coverage, :loc,
                            :locExecutable, :locExecuted, :ccn, :crap);'
            );

            $stmtSelectClassId = $this->dbh->prepare(
              'SELECT code_class_id
                 FROM code_file, code_class
                WHERE code_class.code_file_id    = code_file.code_file_id
                  AND code_file.revision         = :revision
                  AND code_class.code_class_name = :className;'
            );

            $stmtInsertClass = $this->dbh->prepare(
              'INSERT INTO metrics_class
                           (run_id, code_class_id, metrics_class_coverage,
                           metrics_class_loc, metrics_class_loc_executable, metrics_class_loc_executed,
                           metrics_class_aif, metrics_class_ahf,
                           metrics_class_cis, metrics_class_csz, metrics_class_dit,
                           metrics_class_impl, metrics_class_mif, metrics_class_mhf,
                           metrics_class_noc, metrics_class_pf, metrics_class_vars,
                           metrics_class_varsnp, metrics_class_varsi,
                           metrics_class_wmc, metrics_class_wmcnp, metrics_class_wmci)
                     VALUES(:runId, :classId, :coverage, :loc, :locExecutable,
                            :locExecuted, :aif, :ahf, :cis, :csz, :dit, :impl, 
                            :mif, :mhf, :noc, :pf, :vars, :varsnp, :varsi,
                            :wmc, :wmcnp, :wmci);'
            );

            $stmtSelectMethodId = $this->dbh->prepare(
              'SELECT code_method_id
                 FROM code_file, code_class, code_method
                WHERE code_class.code_file_id      = code_file.code_file_id
                  AND code_class.code_class_id     = code_method.code_class_id
                  AND code_file.revision           = :revision
                  AND code_class.code_class_name   = :className
                  AND code_method.code_method_name = :methodName;'
            );

            $stmtInsertMethod = $this->dbh->prepare(
              'INSERT INTO metrics_method
                           (run_id, code_method_id, metrics_method_coverage,
                           metrics_method_loc, metrics_method_loc_executable, metrics_method_loc_executed,
                           metrics_method_ccn, metrics_method_crap)
                     VALUES(:runId, :methodId, :coverage, :loc,
                            :locExecutable, :locExecuted, :ccn, :crap);'
            );

            foreach ($fileMetrics->getFunctions() as $functionMetrics) {
                $functionName = $functionMetrics->getFunction()->getName();

                $stmtSelectFunctionId->bindParam(':functionName', $functionName, PDO::PARAM_STR);
                $stmtSelectFunctionId->bindParam(':revision', $revision, PDO::PARAM_INT);
                $stmtSelectFunctionId->execute();

                $functionId    = (int)$stmtSelectFunctionId->fetchColumn();
                $coverage      = $functionMetrics->getCoverage();
                $loc           = $functionMetrics->getLoc();
                $locExecutable = $functionMetrics->getLocExecutable();
                $locExecuted   = $functionMetrics->getLocExecuted();
                $ccn           = $functionMetrics->getCCN();
                $crap          = $functionMetrics->getCrapIndex();

                $stmtInsertFunction->bindParam(':runId', $runId, PDO::PARAM_INT);
                $stmtInsertFunction->bindParam(':functionId', $functionId, PDO::PARAM_INT);
                $stmtInsertFunction->bindParam(':coverage', $coverage);
                $stmtInsertFunction->bindParam(':loc', $loc, PDO::PARAM_INT);
                $stmtInsertFunction->bindParam(':locExecutable', $locExecutable, PDO::PARAM_INT);
                $stmtInsertFunction->bindParam(':locExecuted', $locExecuted, PDO::PARAM_INT);
                $stmtInsertFunction->bindParam(':ccn', $ccn);
                $stmtInsertFunction->bindParam(':crap', $crap);
                $stmtInsertFunction->execute();
            }

            foreach ($fileMetrics->getClasses() as $classMetrics) {
                $className = $classMetrics->getClass()->getName();

                $stmtSelectClassId->bindParam(':className', $className, PDO::PARAM_STR);
                $stmtSelectClassId->bindParam(':revision', $revision, PDO::PARAM_INT);
                $stmtSelectClassId->execute();

                $classId       = (int)$stmtSelectClassId->fetchColumn();
                $coverage      = $classMetrics->getCoverage();
                $loc           = $classMetrics->getLoc();
                $locExecutable = $classMetrics->getLocExecutable();
                $locExecuted   = $classMetrics->getLocExecuted();
                $aif           = $classMetrics->getAIF();
                $ahf           = $classMetrics->getAHF();
                $cis           = $classMetrics->getCIS();
                $csz           = $classMetrics->getCSZ();
                $dit           = $classMetrics->getDIT();
                $impl          = $classMetrics->getIMPL();
                $mif           = $classMetrics->getMIF();
                $mhf           = $classMetrics->getMHF();
                $noc           = $classMetrics->getNOC();
                $pf            = $classMetrics->getPF();
                $vars          = $classMetrics->getVARS();
                $varsnp        = $classMetrics->getVARSnp();
                $varsi         = $classMetrics->getVARSi();
                $wmc           = $classMetrics->getWMC();
                $wmcnp         = $classMetrics->getWMCnp();
                $wmci          = $classMetrics->getWMCi();

                $stmtInsertClass->bindParam(':runId', $runId, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':classId', $classId, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':coverage', $coverage);
                $stmtInsertClass->bindParam(':loc', $loc, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':locExecutable', $locExecutable, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':locExecuted', $locExecuted, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':aif', $aif);
                $stmtInsertClass->bindParam(':ahf', $ahf);
                $stmtInsertClass->bindParam(':cis', $cis, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':csz', $csz, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':dit', $dit, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':impl', $impl, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':mif', $mif);
                $stmtInsertClass->bindParam(':mhf', $mhf);
                $stmtInsertClass->bindParam(':noc', $noc, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':pf', $pf);
                $stmtInsertClass->bindParam(':vars', $vars, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':varsnp', $varsnp, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':varsi', $varsi, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':wmc', $wmc, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':wmcnp', $wmcnp, PDO::PARAM_INT);
                $stmtInsertClass->bindParam(':wmci', $wmci, PDO::PARAM_INT);
                $stmtInsertClass->execute();

                foreach ($classMetrics->getMethods() as $methodMetrics) {
                    $methodName = $methodMetrics->getMethod()->getName();

                    $stmtSelectMethodId->bindParam(':className', $className, PDO::PARAM_STR);
                    $stmtSelectMethodId->bindParam(':methodName', $methodName, PDO::PARAM_STR);
                    $stmtSelectMethodId->bindParam(':revision', $revision, PDO::PARAM_INT);
                    $stmtSelectMethodId->execute();

                    $methodId      = (int)$stmtSelectMethodId->fetchColumn();
                    $coverage      = $methodMetrics->getCoverage();
                    $loc           = $methodMetrics->getLoc();
                    $locExecutable = $methodMetrics->getLocExecutable();
                    $locExecuted   = $methodMetrics->getLocExecuted();
                    $ccn           = $methodMetrics->getCCN();
                    $crap          = $methodMetrics->getCrapIndex();

                    $stmtInsertMethod->bindParam(':runId', $runId, PDO::PARAM_INT);
                    $stmtInsertMethod->bindParam(':methodId', $methodId, PDO::PARAM_INT);
                    $stmtInsertMethod->bindParam(':coverage', $coverage);
                    $stmtInsertMethod->bindParam(':loc', $loc, PDO::PARAM_INT);
                    $stmtInsertMethod->bindParam(':locExecutable', $locExecutable, PDO::PARAM_INT);
                    $stmtInsertMethod->bindParam(':locExecuted', $locExecuted, PDO::PARAM_INT);
                    $stmtInsertMethod->bindParam(':ccn', $ccn);
                    $stmtInsertMethod->bindParam(':crap', $crap);
                    $stmtInsertMethod->execute();
                }
            }

            unset($stmtSelectFunctionId);
            unset($stmtInsertFunction);
            unset($stmtSelectClassId);
            unset($stmtInsertClass);
            unset($stmtSelectMethodId);
            unset($stmtInsertMethod);

            $stmt = $this->dbh->prepare(
              'SELECT code_line_id, code_line_covered
                 FROM code_line
                WHERE code_file_id     = :fileId
                  AND code_line_number = :lineNumber;'
            );

            $stmt2 = $this->dbh->prepare(
              'UPDATE code_line
                  SET code_line_covered = :lineCovered
                WHERE code_line_id      = :lineId;'
            );

            $stmt3 = $this->dbh->prepare(
              'INSERT INTO code_coverage
                      (test_id, code_line_id)
                VALUES(:testId, :lineId);'
            );

            for ($lineNumber = 1; $lineNumber <= $loc; $lineNumber++) {
                $coveringTests = PHPUnit_Util_CodeCoverage::getCoveringTests(
                  $codeCoverage, $file, $lineNumber
                );

                if (is_array($coveringTests)) {
                    $stmt->bindParam(':fileId', $fileId, PDO::PARAM_INT);
                    $stmt->bindParam(':lineNumber', $lineNumber, PDO::PARAM_INT);
                    $stmt->execute();

                    $codeLineId      = (int)$stmt->fetchColumn(0);
                    $oldCoverageFlag = (int)$stmt->fetchColumn(1);
                    $newCoverageFlag = isset($summary[$file][$lineNumber]) ? 1 : 0;

                    if (($oldCoverageFlag == 0 && $newCoverageFlag != 0) ||
                        ($oldCoverageFlag <  0 && $newCoverageFlag >  0)) {
                        $stmt2->bindParam(':lineCovered', $newCoverageFlag, PDO::PARAM_INT);
                        $stmt2->bindParam(':lineId', $codeLineId, PDO::PARAM_INT);
                        $stmt2->execute();
                    }

                    foreach ($coveringTests as $test) {
                        $stmt3->bindParam(':testId', $test->__db_id, PDO::PARAM_INT);
                        $stmt3->bindParam(':lineId', $codeLineId, PDO::PARAM_INT);
                        $stmt3->execute();
                    }
                }
            }
        }

        unset($stmt);
        unset($stmt2);
        unset($stmt3);

        $stmt = $this->dbh->prepare(
          'SELECT code_method.code_method_id
             FROM code_class, code_method
            WHERE code_class.code_class_id     = code_method.code_class_id
              AND code_class.code_class_name   = :className
              AND code_method.code_method_name = :methodName;'
        );

        $stmt2 = $this->dbh->prepare(
          'UPDATE test
              SET code_method_id = :methodId
            WHERE test_id = :testId;'
        );

        foreach ($result->topTestSuite() as $test) {
            if ($test instanceof PHPUnit_Framework_TestCase) {
                $className  = get_class($test);
                $methodName = $test->getName();

                $stmt->bindParam(':className', $className, PDO::PARAM_STR);
                $stmt->bindParam(':methodName', $methodName, PDO::PARAM_STR);
                $stmt->execute();

                $methodId = (int)$stmt->fetchColumn();

                $stmt2->bindParam(':methodId', $methodId, PDO::PARAM_INT);
                $stmt2->bindParam(':testId', $test->__db_id, PDO::PARAM_INT);
                $stmt2->execute();
            }
        }

        unset($stmt);
        unset($stmt2);

        $stmt = $this->dbh->prepare(
          'INSERT INTO metrics_project
                       (run_id, metrics_project_cls, metrics_project_clsa,
                       metrics_project_clsc, metrics_project_roots,
                       metrics_project_leafs, metrics_project_interfs,
                       metrics_project_maxdit)
                 VALUES(:runId, :cls, :clsa, :clsc, :roots, :leafs,
                        :interfs, :maxdit);'
        );

        $cls     = $projectMetrics->getCLS();
        $clsa    = $projectMetrics->getCLSa();
        $clsc    = $projectMetrics->getCLSc();
        $interfs = $projectMetrics->getInterfs();
        $roots   = $projectMetrics->getRoots();
        $leafs   = $projectMetrics->getLeafs();
        $maxDit  = $projectMetrics->getMaxDit();

        $stmt->bindParam(':runId', $runId, PDO::PARAM_INT);
        $stmt->bindParam(':cls', $cls, PDO::PARAM_INT);
        $stmt->bindParam(':clsa', $clsa, PDO::PARAM_INT);
        $stmt->bindParam(':clsc', $clsc, PDO::PARAM_INT);
        $stmt->bindParam(':roots', $roots, PDO::PARAM_INT);
        $stmt->bindParam(':leafs', $leafs, PDO::PARAM_INT);
        $stmt->bindParam(':interfs', $interfs, PDO::PARAM_INT);
        $stmt->bindParam(':maxdit', $maxDit, PDO::PARAM_INT);
        $stmt->execute();

        unset($stmt);

        $stmt = $this->dbh->prepare(
          'UPDATE code_class
              SET code_class_parent_id = :parentClassId
            WHERE code_class_id = :classId;'
        );

        $stmt2 = $this->dbh->prepare(
          'SELECT code_class.code_class_id as code_class_id
             FROM code_class, code_file
            WHERE code_class.code_file_id    = code_file.code_file_id
              AND code_file.revision         = :revision
              AND code_class.code_class_name = :parentClassName;'
        );

        foreach ($storedClasses as $className => $classId) {
            $class       = new ReflectionClass($className);
            $parentClass = $class->getParentClass();

            if ($parentClass !== FALSE) {
                $parentClassName = $parentClass->getName();
                $parentClassId   = 0;

                if (isset($storedClasses[$parentClassName])) {
                    $parentClassId = $storedClasses[$parentClassName];
                } else {
                    $stmt2->bindParam(':parentClassName', $parentClassName, PDO::PARAM_STR);
                    $stmt2->bindParam(':revision', $revision, PDO::PARAM_INT);
                    $stmt2->execute();

                    $parentClassId = (int)$stmt->fetchColumn();
                }

                if ($parentClassId > 0) {
                    $stmt->bindParam(':classId', $classId, PDO::PARAM_INT);
                    $stmt->bindParam(':parentClassId', $parentClassId, PDO::PARAM_INT);
                    $stmt->execute();
                }
            }
        }

        unset($stmt);
        unset($stmt2);

        $this->dbh->commit();
    }
}
?>
